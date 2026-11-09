<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\Order;
use App\Domain\Enum\OrderStatus;

interface OrderRepositoryPort
{
    /**
     * @return Order[]
     */
    public function findAll(?OrderStatus $status = null): array;

    public function findById(int $id): ?Order;

    public function findByCode(string $orderCode): ?Order;

    public function save(Order $order): int;

    public function updateStatus(int $id, OrderStatus $status): void;

    /**
     * @return array{total_orders: int, pending_orders: int, total_revenue: float}
     */
    public function getSummaryStats(): array;
}
