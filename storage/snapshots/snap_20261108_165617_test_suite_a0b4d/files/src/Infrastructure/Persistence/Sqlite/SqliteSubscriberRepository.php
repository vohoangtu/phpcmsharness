<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Subscriber;
use App\Domain\Port\Outbound\SubscriberRepositoryPort;
use PDO;

/**
 * Driven SQLite Adapter for newsletter subscribers.
 */
final readonly class SqliteSubscriberRepository implements SubscriberRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            code TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            email TEXT
        )');

        $columns = $this->pdo->query('PRAGMA table_info(subscribers)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('email', $columns, true)) {
            $this->pdo->exec('ALTER TABLE subscribers ADD COLUMN email TEXT');
        }
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_subscribers_email ON subscribers(email COLLATE NOCASE) WHERE email IS NOT NULL AND email != ''");
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM subscribers ORDER BY id DESC');
        $rows = $stmt ? $stmt->fetchAll() : [];

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function findById(int $id): ?Subscriber
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscribers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?Subscriber
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscribers WHERE email = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(Subscriber $entity): int
    {
        $now = date('Y-m-d H:i:s');
        if ($entity->id === null) {
            $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO subscribers (name, code, is_active, created_at, email) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $entity->name !== '' ? $entity->name : $entity->email,
                $entity->code,
                $entity->isActive ? 1 : 0,
                $now,
                $entity->email !== '' ? $entity->email : null,
            ]);

            if ($entity->email !== '') {
                $existing = $this->findByEmail($entity->email);
                if ($existing !== null) {
                    return $existing->id ?? (int) $this->pdo->lastInsertId();
                }
            }

            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('UPDATE subscribers SET name = ?, code = ?, is_active = ?, email = ? WHERE id = ?');
        $stmt->execute([$entity->name, $entity->code, $entity->isActive ? 1 : 0, $entity->email, $entity->id]);

        return $entity->id;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM subscribers WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Subscriber
    {
        return new Subscriber(
            id: (int) $row['id'],
            email: (string) ($row['email'] ?? ''),
            isActive: (bool) ((int) $row['is_active']),
            createdAt: (string) $row['created_at'],
            name: (string) $row['name'],
            code: (string) $row['code']
        );
    }
}
