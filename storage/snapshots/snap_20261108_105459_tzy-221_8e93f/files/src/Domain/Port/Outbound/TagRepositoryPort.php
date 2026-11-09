<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\Tag;

interface TagRepositoryPort
{
    /**
     * @return Tag[]
     */
    public function findAll(): array;

    public function save(Tag $tag): int;

    public function delete(int $id): void;

    /**
     * @param string[] $tagNames
     */
    public function syncPostTags(int $postId, array $tagNames): void;

    /**
     * @param string[] $tagNames
     */
    public function syncProductTags(int $productId, array $tagNames): void;
}
