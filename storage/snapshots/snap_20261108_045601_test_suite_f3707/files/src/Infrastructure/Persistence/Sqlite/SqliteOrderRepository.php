<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Order;
use App\Domain\Entity\OrderItem;
use App\Domain\Enum\OrderStatus;
use App\Domain\Port\Outbound\OrderRepositoryPort;
use PDO;

final readonly class SqliteOrderRepository implements OrderRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(?OrderStatus $status = null): array
    {
        $sql = 'SELECT * FROM orders WHERE 1=1';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status->value;
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function findById(int $id): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findByCode(string $orderCode): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE order_code = ? LIMIT 1');
        $stmt->execute([$orderCode]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function save(Order $order): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO orders (order_code, customer_name, customer_email, customer_phone, customer_address, note, total_amount, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $order->orderCode,
            $order->customerName,
            $order->customerEmail,
            $order->customerPhone,
            $order->customerAddress,
            $order->note,
            $order->totalAmount,
            $order->status->value,
            date('Y-m-d H:i:s'),
        ]);
        $orderId = (int) $this->pdo->lastInsertId();

        $stmtItem = $this->pdo->prepare('
            INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        foreach ($order->items as $item) {
            $stmtItem->execute([
                $orderId,
                $item->productId,
                $item->productName,
                $item->unitPrice,
                $item->quantity,
                $item->subtotal,
            ]);
        }

        return $orderId;
    }

    public function updateStatus(int $id, OrderStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute([$status->value, $id]);
    }

    public function getSummaryStats(): array
    {
        $totalOrders = (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $pendingOrders = (int) $this->pdo->query('SELECT COUNT(*) FROM orders WHERE status = "pending"')->fetchColumn();
        $totalRevenue = (float) $this->pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status != "cancelled"')->fetchColumn();

        return [
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'total_revenue' => $totalRevenue,
        ];
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): Order
    {
        $orderId = (int) $r['id'];
        $stmtItems = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $stmtItems->execute([$orderId]);
        $itemRows = $stmtItems->fetchAll();

        $items = array_map(fn(array $ir) => new OrderItem(
            productId: (int) $ir['product_id'],
            productName: (string) $ir['product_name'],
            unitPrice: (float) $ir['unit_price'],
            quantity: (int) $ir['quantity'],
            subtotal: (float) $ir['subtotal']
        ), $itemRows);

        return new Order(
            id: $orderId,
            orderCode: (string) $r['order_code'],
            customerName: (string) $r['customer_name'],
            customerEmail: (string) $r['customer_email'],
            customerPhone: (string) $r['customer_phone'],
            customerAddress: (string) $r['customer_address'],
            note: (string) $r['note'],
            totalAmount: (float) $r['total_amount'],
            status: OrderStatus::tryFrom((string) $r['status']) ?? OrderStatus::PENDING,
            items: $items,
            createdAt: (string) $r['created_at']
        );
    }
}
