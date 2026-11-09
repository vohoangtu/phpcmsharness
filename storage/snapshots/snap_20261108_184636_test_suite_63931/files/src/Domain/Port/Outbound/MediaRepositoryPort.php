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
}
