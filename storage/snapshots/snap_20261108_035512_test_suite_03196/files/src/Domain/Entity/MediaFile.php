<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final readonly class MediaFile
{
    public function __construct(
        public ?int $id,
        public string $filename,
        public string $originalName,
        public string $filePath,
        public string $mimeType,
        public int $fileSize,
        public string $altText,
        public string $createdAt = ''
    ) {
    }

    public function formattedSize(): string
    {
        if ($this->fileSize >= 1048576) {
            return number_format($this->fileSize / 1048576, 2) . ' MB';
        }
        return number_format(max(1, $this->fileSize / 1024), 1) . ' KB';
    }
}
