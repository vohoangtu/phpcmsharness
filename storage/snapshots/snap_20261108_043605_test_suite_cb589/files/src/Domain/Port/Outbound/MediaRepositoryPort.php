<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\MediaFile;

interface MediaRepositoryPort
{
    /**
     * @return MediaFile[]
     */
    public function findAll(): array;

    public function findById(int $id): ?MediaFile;

    public function save(MediaFile $media): int;

    public function delete(int $id): void;
}
