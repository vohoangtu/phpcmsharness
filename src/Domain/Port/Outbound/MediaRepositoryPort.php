<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\MediaFile;

interface MediaRepositoryPort
{
    /**
     * @return MediaFile[]
     */
    public function findAll(?int $limit = null, int $offset = 0): array;

    public function countAll(): int;

    public function findById(int $id): ?MediaFile;

    public function findByFilePath(string $filePath): ?MediaFile;

    public function save(MediaFile $media): int;

    public function delete(int $id): void;

    /** @return array<int, array{id:int,name:string,parent_id:?int}> */
    public function findFolders(): array;

    public function createFolder(string $name, ?int $parentId = null): int;

    /** @param int[] $mediaIds */
    public function moveToFolder(array $mediaIds, ?int $folderId): void;

    public function updateMetadata(int $id, string $altText, string $tags): void;

    /** @param int[] $mediaIds */
    public function markUsed(array $mediaIds): void;

    /** @return MediaFile[] */
    public function findRecentlyUsed(int $limit = 6): array;
}
