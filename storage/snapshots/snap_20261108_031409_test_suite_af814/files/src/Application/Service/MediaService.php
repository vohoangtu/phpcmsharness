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
            altText: $media->altText
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
