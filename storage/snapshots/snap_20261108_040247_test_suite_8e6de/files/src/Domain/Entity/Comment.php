<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\CommentStatus;

final readonly class Comment
{
    public function __construct(
        public ?int $id,
        public string $commentableType, // 'post' | 'product'
        public int $commentableId,
        public string $targetTitle,
        public string $authorName,
        public string $authorEmail,
        public string $content,
        public int $rating,
        public CommentStatus $status,
        public string $createdAt = ''
    ) {
    }
}
