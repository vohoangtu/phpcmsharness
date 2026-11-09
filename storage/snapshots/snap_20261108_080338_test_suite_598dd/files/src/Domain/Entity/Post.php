<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\ContentStatus;

final class Post
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public ?int $id,
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $content,
        public string $featuredImage,
        public ContentStatus $status,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $authorId,
        public string $authorName,
        public int $views = 0,
        public array $tags = [],
        public string $createdAt = '',
        public string $updatedAt = ''
    ) {
    }

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::PUBLISHED;
    }
}
