<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\MediaFile;

interface MediaStoragePort
{
    /**
     * @param array<string, mixed> $uploadedFile $_FILES entry
     */
    public function storeUploadedFile(array $uploadedFile, string $altText = ''): MediaFile;

    public function deletePhysicalFile(string $filename): void;
}
