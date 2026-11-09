<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final readonly class WorkerJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public string $type,
        public array $payload,
        public string $status,
        public int $attempts,
        public ?string $runAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $error,
        public ?string $result,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
