<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

interface LoginAttemptRepositoryPort
{
    /** @return array{failures: int, window_started_at: int, blocked_until: int}|null */
    public function find(string $identifierHash): ?array;

    public function save(string $identifierHash, int $failures, int $windowStartedAt, int $blockedUntil): void;

    public function delete(string $identifierHash): void;

    public function deleteBefore(int $timestamp): void;
}
