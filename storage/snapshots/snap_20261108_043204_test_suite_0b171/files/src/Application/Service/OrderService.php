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
use RuntimeException;

final readonly class OrderService
{
    public function __construct(
        private OrderRepositoryPort $orderRepository,
        private ProductRepositoryPort $productRepository,
        private CartPort $cart
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
        $cartData = $this->getCartDetailed();
        if (empty($cartData['items'])) {
            throw new RuntimeException('Giỏ hàng của bạn đang trống.');
        }

        $orderItems = [];
        foreach ($cartData['items'] as $row) {
            /** @var Product $product */
            $product = $row['product'];
            $qty = (int) $row['quantity'];
            $orderItems[] = new OrderItem(
                productId: (int) $product->id,
                productName: $product->name,
                unitPrice: $product->effectivePrice(),
                quantity: $qty,
                subtotal: (float) $row['subtotal']
            );
            $this->productRepository->decrementStock((int) $product->id, $qty);
        }

        $orderCode = 'ORD-' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 6));
        $order = new Order(
            id: null,
            orderCode: $orderCode,
            customerName: trim((string) ($customerData['customer_name'] ?? 'Khách hàng')),
            customerEmail: trim((string) ($customerData['customer_email'] ?? '')),
            customerPhone: trim((string) ($customerData['customer_phone'] ?? '')),
            customerAddress: trim((string) ($customerData['customer_address'] ?? '')),
            note: trim((string) ($customerData['note'] ?? '')),
            totalAmount: (float) $cartData['total'],
            status: OrderStatus::PENDING,
            items: $orderItems
        );

        $orderId = $this->orderRepository->save($order);
        $this->cart->clear();

        return $this->orderRepository->findById($orderId) ?? $order;
    }

    /**
     * @return Order[]
     */
    public function listOrders(?OrderStatus $status = null): array
    {
        return $this->orderRepository->findAll($status);
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
}
