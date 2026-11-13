<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\WorkerJob;

interface WorkerJobQueuePort
{
    /** @param array<string, mixed> $payload */
    public function enqueue(string $type, array $payload): int;
    public function get(int $id): ?WorkerJob;
    public function hasActiveHarnessTask(int $taskId): bool;
    public function claimNext(): ?WorkerJob;
    public function recoverRunning(): void;
    public function finish(int $id, string $status, ?string $result, ?string $error): void;
    public function retry(int $id): bool;
    public function cancel(int $id): bool;
    /** @return WorkerJob[] */
    public function list(int $limit = 100): array;
}
