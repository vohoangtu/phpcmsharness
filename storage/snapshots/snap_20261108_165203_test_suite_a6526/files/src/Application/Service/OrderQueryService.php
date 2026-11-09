<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Order;
use App\Domain\Enum\OrderStatus;
use App\Domain\Port\Outbound\OrderRepositoryPort;

/** Read use cases for order administration. */
final readonly class OrderQueryService
{
    public function __construct(private OrderRepositoryPort $orderRepository)
    {
    }

    /** @return Order[] */
    public function listOrders(?OrderStatus $status = null, ?int $limit = null, int $offset = 0, string $query = ''): array
    {
        return $this->orderRepository->findAll($status, $limit, $offset, $this->normalizeQuery($query));
    }

    public function countOrders(?OrderStatus $status = null, string $query = ''): int
    {
        return $this->orderRepository->countMatching($status, $this->normalizeQuery($query));
    }

    public function getOrderById(int $id): ?Order
    {
        return $this->orderRepository->findById($id);
    }

    /** @return array{total_orders: int, pending_orders: int, total_revenue: float} */
    public function getSummaryStats(): array
    {
        return $this->orderRepository->getSummaryStats();
    }

    private function normalizeQuery(string $query): string
    {
        return substr(trim($query), 0, 100);
    }
}
