<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Order;
use App\Domain\Entity\OrderItem;
use App\Domain\Entity\Product;
use App\Domain\Enum\OrderStatus;
use App\Domain\Port\Outbound\CartPort;
use App\Domain\Port\Outbound\OrderRepositoryPort;
use App\Domain\Port\Outbound\ProductRepositoryPort;
use App\Domain\Port\Outbound\TransactionPort;
use RuntimeException;
use Throwable;

final readonly class OrderService
{
    public function __construct(
        private OrderRepositoryPort $orderRepository,
        private ProductRepositoryPort $productRepository,
        private CartPort $cart,
        private TransactionPort $transaction
    ) {
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
        $customerData = $this->validateCheckoutData($customerData);
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

        return $savedOrder;
    }

    /**
     * @return Order[]
     */
    public function listOrders(?OrderStatus $status = null, ?int $limit = null, int $offset = 0, string $query = ''): array
    {
        return $this->orderRepository->findAll($status, $limit, $offset, $this->normalizeOrderQuery($query));
    }

    public function countOrders(?OrderStatus $status = null, string $query = ''): int
    {
        return $this->orderRepository->countMatching($status, $this->normalizeOrderQuery($query));
    }

    public function getOrderById(int $id): ?Order
    {
        return $this->orderRepository->findById($id);
    }

    public function updateOrderStatus(int $id, OrderStatus $status): void
    {
        $this->orderRepository->updateStatus($id, $status);
    }

    /**
     * @return array{total_orders: int, pending_orders: int, total_revenue: float}
     */
    public function getSummaryStats(): array
    {
        return $this->orderRepository->getSummaryStats();
    }

    private function normalizeOrderQuery(string $query): string
    {
        return substr(trim($query), 0, 100);
    }

    /**
     * @param array<string, mixed> $customerData
     * @return array{customer_name: string, customer_email: string, customer_phone: string, customer_address: string, note: string}
     */
    private function validateCheckoutData(array $customerData): array
    {
        $name = $this->checkoutText($customerData, 'customer_name', 'Họ và tên', 120);
        $email = $this->checkoutText($customerData, 'customer_email', 'Email', 254);
        $phone = $this->checkoutText($customerData, 'customer_phone', 'Số điện thoại', 40);
        $address = $this->checkoutText($customerData, 'customer_address', 'Địa chỉ giao hàng', 500);
        $note = $this->checkoutText($customerData, 'note', 'Ghi chú', 1000, false);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email không đúng định dạng. Vui lòng kiểm tra lại.');
        }

        if (preg_match('/^[0-9+().\s-]+$/u', $phone) !== 1) {
            throw new RuntimeException('Số điện thoại chỉ được chứa chữ số, dấu +, dấu ngoặc, dấu chấm hoặc dấu gạch ngang.');
        }
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $digitCount = is_string($phoneDigits) ? strlen($phoneDigits) : 0;
        if ($digitCount < 7 || $digitCount > 15) {
            throw new RuntimeException('Số điện thoại phải có từ 7 đến 15 chữ số.');
        }

        return [
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'customer_address' => $address,
            'note' => $note,
        ];
    }

    /** @param array<string, mixed> $customerData */
    private function checkoutText(array $customerData, string $key, string $label, int $maxLength, bool $required = true): string
    {
        $value = $customerData[$key] ?? '';
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('%s không hợp lệ.', $label));
        }

        $value = trim($value);
        if ($required && $value === '') {
            throw new RuntimeException(sprintf('Vui lòng nhập %s.', mb_strtolower($label)));
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('%s không được dài quá %d ký tự.', $label, $maxLength));
        }

        return $value;
    }
}
