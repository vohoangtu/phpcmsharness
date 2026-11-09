<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\MediaFile;
use App\Domain\Port\Outbound\MediaRepositoryPort;
use PDO;

final readonly class SqliteMediaRepository implements MediaRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAll(?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM media ORDER BY id DESC';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, max(0, $offset), PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->pdo->query($sql);
        }
        $rows = $stmt ? $stmt->fetchAll() : [];
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM media')->fetchColumn();
    }

    public function findById(int $id): ?MediaFile
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ? $this->hydrate($r) : null;
    }

    public function save(MediaFile $media): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO media (filename, original_name, file_path, mime_type, file_size, alt_text, created_at, width, height)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $media->filename,
            $media->originalName,
            $media->filePath,
            $media->mimeType,
            $media->fileSize,
            $media->altText,
            date('Y-m-d H:i:s'),
            $media->width,
            $media->height,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): MediaFile
    {
        return new MediaFile(
            id: (int) $r['id'],
            filename: (string) $r['filename'],
            originalName: (string) $r['original_name'],
            filePath: (string) $r['file_path'],
            mimeType: (string) $r['mime_type'],
            fileSize: (int) $r['file_size'],
            altText: (string) $r['alt_text'],
            createdAt: (string) $r['created_at'],
            width: isset($r['width']) ? (int) $r['width'] : null,
            height: isset($r['height']) ? (int) $r['height'] : null
        );
    }
}
