<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\User;
use App\Domain\Port\Outbound\UserRepositoryPort;
use PDO;

final readonly class SqliteUserRepository implements UserRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM users ORDER BY id ASC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ? $this->hydrate($r) : null;
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $r = $stmt->fetch();
        return $r ? $this->hydrate($r) : null;
    }

    public function save(User $user): int
    {
        if ($user->id === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO users (username, email, password_hash, display_name, role, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $user->username,
                $user->email,
                $user->passwordHash,
                $user->displayName,
                $user->role,
                date('Y-m-d H:i:s'),
            ]);
            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('
            UPDATE users SET email = ?, password_hash = ?, display_name = ?, role = ? WHERE id = ?
        ');
        $stmt->execute([
            $user->email,
            $user->passwordHash,
            $user->displayName,
            $user->role,
            $user->id,
        ]);
        return $user->id;
    }

    public function delete(int $id): void
    {
        if ($id === 1) {
            return; // Không xóa tài khoản root admin
        }
        $stmt = $this->pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): User
    {
        return new User(
            id: (int) $r['id'],
            username: (string) $r['username'],
            email: (string) $r['email'],
            passwordHash: (string) $r['password_hash'],
            displayName: (string) $r['display_name'],
            role: (string) $r['role'],
            createdAt: (string) $r['created_at']
        );
    }
}
