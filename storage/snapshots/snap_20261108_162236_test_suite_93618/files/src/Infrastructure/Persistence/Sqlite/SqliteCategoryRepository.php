<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Category;
use App\Domain\Port\Outbound\CategoryRepositoryPort;
use PDO;

final readonly class SqliteCategoryRepository implements CategoryRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findByType(string $type): array
    {
        $table = $type === 'product' ? 'products' : 'posts';
        $sql = sprintf('
            SELECT c.*, COUNT(t.id) AS item_count
            FROM categories c
            LEFT JOIN %s t ON t.category_id = c.id AND t.status != "trash"
            WHERE c.type = ?
            GROUP BY c.id
            ORDER BY c.name ASC
        ', $table);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$type]);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $r) => new Category(
            id: (int) $r['id'],
            name: (string) $r['name'],
            slug: (string) $r['slug'],
            type: (string) $r['type'],
            description: (string) $r['description'],
            itemCount: (int) $r['item_count']
        ), $rows);
    }

    public function findById(int $id): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        return new Category(
            id: (int) $r['id'],
            name: (string) $r['name'],
            slug: (string) $r['slug'],
            type: (string) $r['type'],
            description: (string) $r['description']
        );
    }

    public function findBySlug(string $slug, string $type): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = ? AND type = ? LIMIT 1');
        $stmt->execute([$slug, $type]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        return new Category(
            id: (int) $r['id'],
            name: (string) $r['name'],
            slug: (string) $r['slug'],
            type: (string) $r['type'],
            description: (string) $r['description']
        );
    }

    public function save(Category $category): int
    {
        if ($category->id === null) {
            $stmt = $this->pdo->prepare('INSERT INTO categories (name, slug, type, description) VALUES (?, ?, ?, ?)');
            $stmt->execute([$category->name, $category->slug, $category->type, $category->description]);
            return (int) $this->pdo->lastInsertId();
        }
        $stmt = $this->pdo->prepare('UPDATE categories SET name = ?, slug = ?, description = ? WHERE id = ?');
        $stmt->execute([$category->name, $category->slug, $category->description, $category->id]);
        return $category->id;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->execute([$id]);
    }
}
