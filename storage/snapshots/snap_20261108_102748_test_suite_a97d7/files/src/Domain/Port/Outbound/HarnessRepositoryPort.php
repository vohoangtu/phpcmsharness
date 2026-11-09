<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\HarnessTask;

interface HarnessRepositoryPort
{
    /**
     * @return HarnessTask[]
     */
    public function findAll(): array;

    public function findById(int $id): ?HarnessTask;

    public function save(HarnessTask $task): int;

    public function delete(int $id): void;
}
