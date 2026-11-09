<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\Comment;
use App\Domain\Enum\CommentStatus;

interface CommentRepositoryPort
{
    /**
     * @return Comment[]
     */
    public function findAll(?CommentStatus $status = null, ?string $type = null): array;

    /**
     * @return Comment[]
     */
    public function findApprovedForTarget(string $type, int $targetId): array;

    public function save(Comment $comment): int;

    public function updateStatus(int $id, CommentStatus $status): void;

    public function delete(int $id): void;

    /**
     * @return array<string, int>
     */
    public function countByStatuses(): array;
}
