<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\Comment;
use App\Domain\Enum\CommentStatus;
use App\Domain\Port\Outbound\CommentRepositoryPort;
use PDO;

final readonly class SqliteCommentRepository implements CommentRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(?CommentStatus $status = null, ?string $type = null, ?int $limit = null, int $offset = 0): array
    {
        $sql = '
            SELECT cm.*,
                   CASE
                       WHEN cm.commentable_type = "product" THEN (SELECT name FROM products WHERE id = cm.commentable_id)
                       ELSE (SELECT title FROM posts WHERE id = cm.commentable_id)
                   END AS target_title
            FROM comments cm
            WHERE 1=1
        ';
        $params = [];

        if ($status !== null) {
            $sql .= ' AND cm.status = ?';
            $params[] = $status->value;
        }
        if ($type !== null && $type !== '') {
            $sql .= ' AND cm.commentable_type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY cm.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = max(1, $limit);
            $params[] = max(0, $offset);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function countMatching(?CommentStatus $status = null, ?string $type = null): int
    {
        $sql = 'SELECT COUNT(*) FROM comments cm WHERE 1=1';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND cm.status = ?';
            $params[] = $status->value;
        }
        if ($type !== null && $type !== '') {
            $sql .= ' AND cm.commentable_type = ?';
            $params[] = $type;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findApprovedForTarget(string $type, int $targetId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT cm.*, "" AS target_title
            FROM comments cm
            WHERE cm.commentable_type = ? AND cm.commentable_id = ? AND cm.status = "approved"
            ORDER BY cm.id DESC
        ');
        $stmt->execute([$type, $targetId]);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function save(Comment $comment): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO comments (commentable_type, commentable_id, author_name, author_email, content, rating, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $comment->commentableType,
            $comment->commentableId,
            $comment->authorName,
            $comment->authorEmail,
            $comment->content,
            $comment->rating,
            $comment->status->value,
            date('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $id, CommentStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE comments SET status = ? WHERE id = ?');
        $stmt->execute([$status->value, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM comments WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countByStatuses(): array
    {
        $counts = [
            'all' => 0,
            'approved' => 0,
            'pending' => 0,
            'spam' => 0,
        ];
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS cnt FROM comments GROUP BY status');
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            $st = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            if (isset($counts[$st])) {
                $counts[$st] = $cnt;
            }
            $counts['all'] += $cnt;
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): Comment
    {
        return new Comment(
            id: (int) $r['id'],
            commentableType: (string) $r['commentable_type'],
            commentableId: (int) $r['commentable_id'],
            targetTitle: (string) ($r['target_title'] ?? '#ID ' . $r['commentable_id']),
            authorName: (string) $r['author_name'],
            authorEmail: (string) $r['author_email'],
            content: (string) $r['content'],
            rating: (int) $r['rating'],
            status: CommentStatus::tryFrom((string) $r['status']) ?? CommentStatus::PENDING,
            createdAt: (string) $r['created_at']
        );
    }
}
