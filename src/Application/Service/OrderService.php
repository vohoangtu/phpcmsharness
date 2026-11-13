<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Order;
use App\Domain\Entity\OrderItem;
use App\Domain\Entity\Product;
use App\Domain\Enum\OrderStatus;
use App\Domain\Port\Outbound\CartPort;
use App\Domain\Port\Outbound\OrderRepositoryPort;
use App\Domain\Port\Outbound\OrderOperationsPort;
use App\Domain\Port\Outbound\ProductRepositoryPort;
use App\Domain\Port\Outbound\TransactionPort;
use App\Application\Extension\HookRegistry;
use RuntimeException;
use Throwable;

final readonly class OrderService
{
    private OrderQueryService $orderQueries;
    private CheckoutDataValidator $checkoutDataValidator;

    public function __construct(
        private OrderRepositoryPort $orderRepository,
        private ProductRepositoryPort $productRepository,
        private CartPort $cart,
        private TransactionPort $transaction,
        private OrderOperationsPort $operations,
        private ?HookRegistry $hooks = null
    ) {
        $this->orderQueries = new OrderQueryService($orderRepository);
        $this->checkoutDataValidator = new CheckoutDataValidator();
    }

    /**
     * @return array{items: array<int, array{product: Product, quantity: int, subtotal: float}>, total: float, count: int}
     */
    public function getCartDetailed(): array
    {
        $rawItems = $this->cart->getItems();
        $detailed = [];
        $total = 0.0;
        $count = 0;

        foreach ($rawItems as $productId => $qty) {
            $product = $this->productRepository->findById((int) $productId);
            if ($product === null) {
                $this->cart->remove((int) $productId);
                continue;
            }
            $subtotal = $product->effectivePrice() * $qty;
            $detailed[] = [
                'product' => $product,
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ];
            $total += $subtotal;
            $count += $qty;
        }

        return [
            'items' => $detailed,
            'total' => $total,
            'count' => $count,
        ];
    }

    /**
     * @param array<string, mixed> $customerData
     */
    public function checkout(array $customerData): Order
    {
        $customerData = $this->checkoutDataValidator->validate($customerData);
        $this->transaction->begin();
        try {
            $cartData = $this->getCartDetailed();
            if (empty($cartData['items'])) {
                throw new RuntimeException('Giỏ hàng của bạn đang trống.');
            }

            $orderItems = [];
            foreach ($cartData['items'] as $row) {
                /** @var Product $product */
                $product = $row['product'];
                $qty = (int) $row['quantity'];
                if ($product->status !== \App\Domain\Enum\ContentStatus::PUBLISHED) {
                    throw new RuntimeException(sprintf(
                        'Sản phẩm "%s" hiện không thể đặt mua. Vui lòng cập nhật giỏ hàng.',
                        $product->name
                    ));
                }
                if (!$this->productRepository->decrementStock((int) $product->id, $qty)) {
                    throw new RuntimeException(sprintf('Sản phẩm "%s" không còn đủ số lượng trong kho.', $product->name));
                }
                $orderItems[] = new OrderItem(
                    productId: (int) $product->id,
                    productName: $product->name,
                    unitPrice: $product->effectivePrice(),
                    quantity: $qty,
                    subtotal: (float) $row['subtotal']
                );
            }

            $orderCode = 'ORD-' . strtoupper(bin2hex(random_bytes(12)));
            $order = new Order(
                id: null,
                orderCode: $orderCode,
                customerName: $customerData['customer_name'],
                customerEmail: $customerData['customer_email'],
                customerPhone: $customerData['customer_phone'],
                customerAddress: $customerData['customer_address'],
                note: $customerData['note'],
                totalAmount: (float) $cartData['total'],
                status: OrderStatus::PENDING,
                items: $orderItems
            );

            $orderId = $this->orderRepository->save($order);
            $savedOrder = $this->orderRepository->findById($orderId) ?? $order;
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }

        $this->cart->clear();
        $this->hooks?->doAction('order.created', $savedOrder);

        return $savedOrder;
    }

    /**
     * @return Order[]
     */
    public function listOrders(?OrderStatus $status = null, ?int $limit = null, int $offset = 0, string $query = ''): array
    {
        return $this->orderQueries->listOrders($status, $limit, $offset, $query);
    }

    public function countOrders(?OrderStatus $status = null, string $query = ''): int
    {
        return $this->orderQueries->countOrders($status, $query);
    }

    public function getOrderById(int $id): ?Order
    {
        return $this->orderQueries->getOrderById($id);
    }

    public function updateOrderStatus(int $id, OrderStatus $status, string $actor = 'system'): void
    {
        if ($status !== OrderStatus::CANCELLED) {
            $previousStatus = null;
            $this->transaction->begin();
            try {
                $order = $this->orderRepository->findById($id);
                if ($order === null) throw new RuntimeException('Không tìm thấy đơn hàng.');
                if ($order->status === OrderStatus::CANCELLED) throw new RuntimeException('Không thể mở lại đơn hàng đã hủy vì tồn kho đã được hoàn trả.');
                if ($order->status !== $status && !($order->status === OrderStatus::PENDING && $status === OrderStatus::PROCESSING) && !($order->status === OrderStatus::PROCESSING && $status === OrderStatus::COMPLETED)) throw new RuntimeException('Chuyển trạng thái theo trình tự chờ xử lý → đang giao hàng → hoàn thành.');
                $previousStatus = $order->status;
                $this->orderRepository->updateStatus($id, $status);
                if ($order->status !== $status) $this->operations->addActivity($id, $actor, 'Thay đổi trạng thái đơn', $order->status->label() . ' → ' . $status->label());
                $this->transaction->commit();
            } catch (Throwable $error) { $this->transaction->rollback(); throw $error; }
            if ($previousStatus !== null && $previousStatus !== $status) {
                $updatedOrder = $this->orderRepository->findById($id);
                if ($updatedOrder !== null) $this->hooks?->doAction('order.status_changed', $updatedOrder, $previousStatus, $status, $actor);
            }
            return;
        }

        $this->transaction->begin();
        try {
            $order = $this->orderRepository->findById($id);
            if ($order === null) {
                throw new RuntimeException('Không tìm thấy đơn hàng cần hủy.');
            }
            if ($order->status === OrderStatus::CANCELLED) {
                $this->transaction->commit();
                return;
            }

            if ($this->operations->netPaid($id) > 0) throw new RuntimeException('Phải hoàn toàn bộ tiền đã thu trước khi hủy đơn.');

            foreach ($order->items as $item) {
                $this->productRepository->increaseStock($item->productId, $item->quantity);
            }
            $this->orderRepository->updateStatus($id, OrderStatus::CANCELLED);
            $this->operations->addActivity($id, $actor, 'Hủy đơn', 'Đã hoàn tồn kho.');
            $this->transaction->commit();
            $cancelledOrder = $this->orderRepository->findById($id);
            if ($cancelledOrder !== null) $this->hooks?->doAction('order.status_changed', $cancelledOrder, $order->status, OrderStatus::CANCELLED, $actor);
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }
    }

    /**
     * @return array{total_orders: int, pending_orders: int, total_revenue: float}
     */
    public function getSummaryStats(): array
    {
        return $this->orderQueries->getSummaryStats();
    }

}
