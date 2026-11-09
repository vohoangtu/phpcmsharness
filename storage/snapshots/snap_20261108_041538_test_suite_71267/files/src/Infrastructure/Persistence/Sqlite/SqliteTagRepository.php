<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Tag;
use App\Domain\Port\Outbound\TagRepositoryPort;
use App\Domain\ValueObject\Slug;
use PDO;

final readonly class SqliteTagRepository implements TagRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('
            SELECT t.*,
                   (SELECT COUNT(*) FROM post_tags pt WHERE pt.tag_id = t.id) +
                   (SELECT COUNT(*) FROM product_tags prt WHERE prt.tag_id = t.id) AS usage_count
            FROM tags t
            ORDER BY t.name ASC
        ');
        $rows = $stmt ? $stmt->fetchAll() : [];

        return array_map(fn(array $r) => new Tag(
            id: (int) $r['id'],
            name: (string) $r['name'],
            slug: (string) $r['slug'],
            usageCount: (int) $r['usage_count']
        ), $rows);
    }

    public function save(Tag $tag): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE slug = ? LIMIT 1');
        $stmt->execute([$tag->slug]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $ins = $this->pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)');
        $ins->execute([$tag->name, $tag->slug]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tags WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function syncPostTags(int $postId, array $tagNames): void
    {
        $this->pdo->prepare('DELETE FROM post_tags WHERE post_id = ?')->execute([$postId]);
        $ins = $this->pdo->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)');
        foreach ($tagNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $tagId = $this->save(new Tag(null, $name, (new Slug($name))->value));
            $ins->execute([$postId, $tagId]);
        }
    }

    public function syncProductTags(int $productId, array $tagNames): void
    {
        $this->pdo->prepare('DELETE FROM product_tags WHERE product_id = ?')->execute([$productId]);
        $ins = $this->pdo->prepare('INSERT OR IGNORE INTO product_tags (product_id, tag_id) VALUES (?, ?)');
        foreach ($tagNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $tagId = $this->save(new Tag(null, $name, (new Slug($name))->value));
            $ins->execute([$productId, $tagId]);
        }
    }
}
