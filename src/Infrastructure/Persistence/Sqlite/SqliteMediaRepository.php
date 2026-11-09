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
        $sql = 'SELECT media.*, media_folders.name AS folder_name FROM media LEFT JOIN media_folders ON media.folder_id = media_folders.id ORDER BY media.id DESC';
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

    public function findByFilePath(string $filePath): ?MediaFile
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE file_path = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$filePath]);
        $r = $stmt->fetch();
        return $r ? $this->hydrate($r) : null;
    }

    public function save(MediaFile $media): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO media (filename, original_name, file_path, mime_type, file_size, alt_text, created_at, width, height, folder_id, tags)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            $media->folderId,
            $media->tags,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function findFolders(): array
    {
        $rows = $this->pdo->query('SELECT id, name, parent_id FROM media_folders ORDER BY name COLLATE NOCASE')->fetchAll();
        return array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'parent_id' => $row['parent_id'] === null ? null : (int)$row['parent_id']], $rows);
    }

    public function createFolder(string $name, ?int $parentId = null): int
    {
        $statement = $this->pdo->prepare('INSERT INTO media_folders (name, parent_id) VALUES (?, ?)');
        $statement->execute([$name, $parentId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function moveToFolder(array $mediaIds, ?int $folderId): void
    {
        $statement = $this->pdo->prepare('UPDATE media SET folder_id = ? WHERE id = ?');
        foreach ($mediaIds as $id) $statement->execute([$folderId, $id]);
    }

    public function updateMetadata(int $id, string $altText, string $tags): void
    {
        $statement = $this->pdo->prepare('UPDATE media SET alt_text = ?, tags = ? WHERE id = ?');
        $statement->execute([$altText, $tags, $id]);
    }

    public function markUsed(array $mediaIds): void
    {
        $statement = $this->pdo->prepare('UPDATE media SET last_used_at = ?, use_count = use_count + 1 WHERE id = ?');
        $now = date('Y-m-d H:i:s');
        foreach (array_unique($mediaIds) as $id) $statement->execute([$now, (int)$id]);
    }

    public function findRecentlyUsed(int $limit = 6): array
    {
        $statement = $this->pdo->prepare('SELECT media.*, media_folders.name AS folder_name FROM media LEFT JOIN media_folders ON media.folder_id = media_folders.id WHERE media.last_used_at IS NOT NULL ORDER BY media.last_used_at DESC LIMIT ?');
        $statement->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        return array_map(fn(array $row) => $this->hydrate($row), $statement->fetchAll());
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
            height: isset($r['height']) ? (int) $r['height'] : null,
            folderId: isset($r['folder_id']) ? (int)$r['folder_id'] : null,
            folderName: (string)($r['folder_name'] ?? ''),
            tags: (string)($r['tags'] ?? ''),
            lastUsedAt: isset($r['last_used_at']) ? (string)$r['last_used_at'] : null,
            useCount: (int)($r['use_count'] ?? 0)
        );
    }
}
