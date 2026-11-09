<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Comment;
use App\Domain\Enum\CommentStatus;
use App\Domain\Port\Outbound\CommentRepositoryPort;

final readonly class CommentService
{
    public function __construct(
        private CommentRepositoryPort $commentRepository
    ) {
    }

    /**
     * @return Comment[]
     */
    public function listComments(?CommentStatus $status = null, ?string $type = null, ?int $limit = null, int $offset = 0): array
    {
        return $this->commentRepository->findAll($status, $type, $limit, $offset);
    }

    public function countComments(?CommentStatus $status = null, ?string $type = null): int
    {
        return $this->commentRepository->countMatching($status, $type);
    }

    /**
     * @return Comment[]
     */
    public function getApprovedComments(string $type, int $targetId): array
    {
        return $this->commentRepository->findApprovedForTarget($type, $targetId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addComment(string $type, int $targetId, array $data, bool $autoApprove = true): int
    {
        $rating = isset($data['rating']) ? max(1, min(5, (int) $data['rating'])) : 5;
        $comment = new Comment(
            id: null,
            commentableType: $type,
            commentableId: $targetId,
            targetTitle: '',
            authorName: trim((string) ($data['author_name'] ?? 'Khách')),
            authorEmail: trim((string) ($data['author_email'] ?? '')),
            content: trim((string) ($data['content'] ?? '')),
            rating: $rating,
            status: $autoApprove ? CommentStatus::APPROVED : CommentStatus::PENDING
        );

        return $this->commentRepository->save($comment);
    }

    public function changeStatus(int $id, CommentStatus $status): void
    {
        $this->commentRepository->updateStatus($id, $status);
    }

    public function delete(int $id): void
    {
        $this->commentRepository->delete($id);
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        return $this->commentRepository->countByStatuses();
    }
}
