<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\Post;
use App\Domain\Enum\ContentStatus;

interface PostRepositoryPort
{
    /**
     * @return Post[]
     */
    public function findAll(?ContentStatus $status = null, ?int $categoryId = null, string $search = '', ?int $limit = null): array;

    public function findById(int $id): ?Post;

    public function findBySlug(string $slug): ?Post;

    public function save(Post $post): int;

    public function updateStatus(int $id, ContentStatus $status): void;

    public function incrementViews(int $id): void;

    public function delete(int $id): void;

    /**
     * @return array<string, int>
     */
    public function countByStatuses(): array;
}
