<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Post;
use App\Domain\Enum\ContentStatus;
use App\Domain\Port\Outbound\PostRepositoryPort;
use PDO;

final readonly class SqlitePostRepository implements PostRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(?ContentStatus $status = null, ?int $categoryId = null, string $search = '', ?int $limit = null, int $offset = 0): array
    {
        $sql = '
            SELECT p.*, c.name AS category_name, u.display_name AS author_name
            FROM posts p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.author_id
            WHERE 1=1
        ';
        $params = [];

        if ($status !== null) {
            $sql .= ' AND p.status = ?';
            $params[] = $status->value;
        } else {
            $sql .= ' AND p.status != "trash"';
        }

        if ($categoryId !== null && $categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }

        if ($search !== '') {
            $sql .= ' AND (p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY p.id DESC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = max(0, $offset);
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    public function countMatching(?ContentStatus $status = null, ?int $categoryId = null, string $search = ''): int
    {
        $sql = 'SELECT COUNT(*) FROM posts p WHERE 1=1';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND p.status = ?';
            $params[] = $status->value;
        } else {
            $sql .= ' AND p.status != "trash"';
        }
        if ($categoryId !== null && $categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($search !== '') {
            $sql .= ' AND (p.title LIKE ? OR p.excerpt LIKE ? OR p.content LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?Post
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, c.name AS category_name, u.display_name AS author_name
            FROM posts p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.author_id
            WHERE p.id = ? LIMIT 1
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Post
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, c.name AS category_name, u.display_name AS author_name
            FROM posts p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.author_id
            WHERE p.slug = ? LIMIT 1
        ');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(Post $post): int
    {
        $now = date('Y-m-d H:i:s');
        if ($post->id === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO posts (title, slug, excerpt, content, featured_image, status, category_id, author_id, views, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $post->title,
                $post->slug,
                $post->excerpt,
                $post->content,
                $post->featuredImage,
                $post->status->value,
                $post->categoryId,
                $post->authorId,
                $post->views,
                $now,
                $now,
            ]);
            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('
            UPDATE posts
            SET title = ?, slug = ?, excerpt = ?, content = ?, featured_image = ?, status = ?, category_id = ?, updated_at = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $post->title,
            $post->slug,
            $post->excerpt,
            $post->content,
            $post->featuredImage,
            $post->status->value,
            $post->categoryId,
            $now,
            $post->id,
        ]);
        return $post->id;
    }

    public function updateStatus(int $id, ContentStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE posts SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status->value, date('Y-m-d H:i:s'), $id]);
    }

    public function incrementViews(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE posts SET views = views + 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM posts WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countByStatuses(): array
    {
        $counts = [
            'all' => 0,
            'published' => 0,
            'draft' => 0,
            'trash' => 0,
        ];
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS cnt FROM posts GROUP BY status');
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            $st = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            if (isset($counts[$st])) {
                $counts[$st] = $cnt;
            }
            if ($st !== 'trash') {
                $counts['all'] += $cnt;
            }
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Post
    {
        $postId = (int) $row['id'];
        $stmtTags = $this->pdo->prepare('
            SELECT t.name FROM tags t
            INNER JOIN post_tags pt ON pt.tag_id = t.id
            WHERE pt.post_id = ?
        ');
        $stmtTags->execute([$postId]);
        $tags = array_map('strval', $stmtTags->fetchAll(PDO::FETCH_COLUMN));

        return new Post(
            id: $postId,
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            excerpt: (string) $row['excerpt'],
            content: (string) $row['content'],
            featuredImage: (string) $row['featured_image'],
            status: ContentStatus::tryFrom((string) $row['status']) ?? ContentStatus::DRAFT,
            categoryId: !empty($row['category_id']) ? (int) $row['category_id'] : null,
            categoryName: isset($row['category_name']) ? (string) $row['category_name'] : null,
            authorId: (int) $row['author_id'],
            authorName: (string) ($row['author_name'] ?? 'Admin'),
            views: (int) $row['views'],
            tags: $tags,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at']
        );
    }
}
