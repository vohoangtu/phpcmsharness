<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Port\Outbound\LoginAttemptRepositoryPort;
use PDO;

final readonly class SqliteLoginAttemptRepository implements LoginAttemptRepositoryPort
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS login_attempts (
                identifier_hash TEXT PRIMARY KEY,
                failures INTEGER NOT NULL,
                window_started_at INTEGER NOT NULL,
                blocked_until INTEGER NOT NULL DEFAULT 0
            )
        ');
    }

    public function find(string $identifierHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT failures, window_started_at, blocked_until FROM login_attempts WHERE identifier_hash = ? LIMIT 1');
        $stmt->execute([$identifierHash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'failures' => (int) $row['failures'],
            'window_started_at' => (int) $row['window_started_at'],
            'blocked_until' => (int) $row['blocked_until'],
        ];
    }

    public function save(string $identifierHash, int $failures, int $windowStartedAt, int $blockedUntil): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO login_attempts (identifier_hash, failures, window_started_at, blocked_until)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(identifier_hash) DO UPDATE SET
                failures = excluded.failures,
                window_started_at = excluded.window_started_at,
                blocked_until = excluded.blocked_until
        ');
        $stmt->execute([$identifierHash, $failures, $windowStartedAt, $blockedUntil]);
    }

    public function delete(string $identifierHash): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash = ?');
        $stmt->execute([$identifierHash]);
    }

    public function deleteBefore(int $timestamp): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE window_started_at < ? AND blocked_until < ?');
        $stmt->execute([$timestamp, $timestamp]);
    }
}
