<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\MediaFile;
use App\Domain\Port\Outbound\MediaRepositoryPort;
use App\Domain\Port\Outbound\MediaStoragePort;

final readonly class MediaService
{
    public function __construct(
        private MediaRepositoryPort $mediaRepository,
        private MediaStoragePort $mediaStorage
    ) {
    }

    /**
     * @return MediaFile[]
     */
    public function listAll(): array
    {
        return $this->mediaRepository->findAll();
    }

    /**
     * @return MediaFile[]
     */
    public function listPage(int $limit, int $offset): array
    {
        return $this->mediaRepository->findAll(max(1, $limit), max(0, $offset));
    }

    public function countAll(): int
    {
        return $this->mediaRepository->countAll();
    }

    public function folders(): array { return $this->mediaRepository->findFolders(); }
    public function createFolder(string $name, ?int $parentId = null): int
    {
        $name = trim($name);
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $length > 80) throw new \InvalidArgumentException('Tên thư mục phải có từ 1 đến 80 ký tự.');
        return $this->mediaRepository->createFolder($name, $parentId);
    }
    public function moveToFolder(array $ids, ?int $folderId): void { $this->mediaRepository->moveToFolder($ids, $folderId); }
    public function updateMetadata(int $id, string $altText, string $tags): void { $this->mediaRepository->updateMetadata($id, trim($altText), trim($tags)); }
    public function markUsed(array $ids): void { $this->mediaRepository->markUsed(array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0))); }
    public function recentlyUsed(int $limit = 6): array { return $this->mediaRepository->findRecentlyUsed($limit); }

    /**
     * @param array<string, mixed> $uploadedFile
     */
    public function upload(array $uploadedFile, string $altText = ''): MediaFile
    {
        $media = $this->mediaStorage->storeUploadedFile($uploadedFile, $altText);
        $id = $this->mediaRepository->save($media);

        return new MediaFile(
            id: $id,
            filename: $media->filename,
            originalName: $media->originalName,
            filePath: $media->filePath,
            mimeType: $media->mimeType,
            fileSize: $media->fileSize,
            altText: $media->altText,
            width: $media->width,
            height: $media->height
        );
    }

    public function delete(int $id): void
    {
        $media = $this->mediaRepository->findById($id);
        if ($media !== null) {
            $this->mediaStorage->deletePhysicalFile($media->filename);
            $this->mediaRepository->delete($id);
        }
    }
}
