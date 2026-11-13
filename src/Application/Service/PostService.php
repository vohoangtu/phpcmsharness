<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Post;
use App\Domain\Enum\ContentStatus;
use App\Domain\Port\Outbound\PostRepositoryPort;
use App\Domain\Port\Outbound\TagRepositoryPort;
use App\Domain\ValueObject\Slug;
use App\Application\Extension\HookRegistry;

final readonly class PostService
{
    public function __construct(
        private PostRepositoryPort $postRepository,
        private TagRepositoryPort $tagRepository,
        private ?HookRegistry $hooks = null
    ) {
    }

    /**
     * @return Post[]
     */
    public function listPosts(?ContentStatus $status = null, ?int $categoryId = null, string $search = '', ?int $limit = null, int $offset = 0): array
    {
        return $this->postRepository->findAll($status, $categoryId, $search, $limit, $offset);
    }

    public function countPosts(?ContentStatus $status = null, ?int $categoryId = null, string $search = ''): int
    {
        return $this->postRepository->countMatching($status, $categoryId, $search);
    }

    public function getById(int $id): ?Post
    {
        return $this->postRepository->findById($id);
    }

    public function getPublishedBySlug(string $slug): ?Post
    {
        $post = $this->postRepository->findBySlug($slug);
        if ($post === null || !$post->isPublished()) {
            return null;
        }
        $this->postRepository->incrementViews((int) $post->id);
        return $this->postRepository->findById((int) $post->id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function savePost(array $data, int $authorId): int
    {
        $id = isset($data['id']) && (int) $data['id'] > 0 ? (int) $data['id'] : null;
        $title = trim((string) ($data['title'] ?? 'Bài viết chưa có tiêu đề'));
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = (new Slug($rawSlug !== '' ? $rawSlug : $title))->value;
        $slug = $this->ensureUniqueSlug($slug, $id);

        $status = ContentStatus::tryFrom((string) ($data['status'] ?? 'published')) ?? ContentStatus::PUBLISHED;
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;

        $post = new Post(
            id: $id,
            title: $title,
            slug: $slug,
            excerpt: trim((string) ($data['excerpt'] ?? '')),
            content: (string) ($data['content'] ?? ''),
            featuredImage: trim((string) ($data['featured_image'] ?? '')),
            status: $status,
            categoryId: $categoryId,
            categoryName: null,
            authorId: $authorId,
            authorName: '',
            seoTitle: trim((string) ($data['seo_title'] ?? '')),
            seoDescription: trim((string) ($data['seo_description'] ?? ''))
        );

        $savedId = $this->postRepository->save($post);

        $rawTags = (string) ($data['tags'] ?? '');
        $tagNames = array_values(array_filter(array_map('trim', explode(',', $rawTags)), fn(string $t) => $t !== ''));
        $this->tagRepository->syncPostTags($savedId, $tagNames);
        $savedPost = $this->postRepository->findById($savedId);
        if ($savedPost !== null) $this->hooks?->doAction('post.saved', $savedPost);

        return $savedId;
    }

    public function changeStatus(int $id, ContentStatus $status): void
    {
        $this->postRepository->updateStatus($id, $status);
    }

    public function deletePermanently(int $id): void
    {
        $this->postRepository->delete($id);
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        return $this->postRepository->countByStatuses();
    }

    private function ensureUniqueSlug(string $baseSlug, ?int $ignoreId): string
    {
        $slug = $baseSlug;
        $counter = 2;
        while (true) {
            $existing = $this->postRepository->findBySlug($slug);
            if ($existing === null || ($ignoreId !== null && $existing->id === $ignoreId)) {
                return $slug;
            }
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
    }
}
