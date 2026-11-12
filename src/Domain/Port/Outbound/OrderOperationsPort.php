<?php
declare(strict_types=1);
namespace App\Domain\Port\Outbound;
interface OrderOperationsPort
{
    public function details(int $orderId): array;
    public function paymentDocument(int $transactionId): ?array;
    public function paymentLedger(string $kind, string $query, string $from, string $to, int $limit, int $offset): array;
    public function netPaid(int $orderId): int;
    public function recordPayment(int $orderId, array $data, string $actor): void;
    public function saveShipment(int $orderId, array $data, string $actor): void;
    public function saveInvoice(int $orderId, array $data, string $actor): void;
    public function addAdjustment(int $orderId, array $data, string $actor): void;
    public function addActivity(int $orderId, string $actor, string $event, string $detail): void;
}
