<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\CommentService;
use App\Application\Service\ModuleManagerService;
use App\Application\Service\PostService;
use App\Application\Service\TaxonomyService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Presentation\Controller\BaseController;

final class BlogController extends BaseController
{
    public function index(): void
    {
        $postService = $this->container->get(PostService::class);
        $taxonomyService = $this->container->get(TaxonomyService::class);

        $categoryId = !empty($_GET['category']) ? (int) $_GET['category'] : null;
        $search = trim((string) ($_GET['s'] ?? ''));

        $this->view->renderFrontend('frontend/blog/index', [
            'pageTitle' => 'Tin tức & Bài viết',
            'posts' => $postService->listPosts(ContentStatus::PUBLISHED, $categoryId, $search),
            'categories' => $taxonomyService->getCategories('post'),
            'currentCategory' => $categoryId,
            'search' => $search,
        ]);
    }

    public function show(string $slug): void
    {
        $postService = $this->container->get(PostService::class);
        $commentService = $this->container->get(CommentService::class);
        $moduleManager = $this->container->get(ModuleManagerService::class);

        $post = $postService->getPublishedBySlug($slug);
        if ($post === null) {
            http_response_code(404);
            $this->view->renderFrontend('frontend/404', [
                'pageTitle' => 'Không tìm thấy bài viết',
                'message' => 'Bài viết bạn tìm kiếm không tồn tại hoặc đang ở trạng thái bản nháp.',
            ]);
            return;
        }

        $comments = $moduleManager->isEnabled(ModuleKey::COMMENTS)
            ? $commentService->getApprovedComments('post', (int) $post->id)
            : [];

        $this->view->renderFrontend('frontend/blog/show', [
            'pageTitle' => $post->title,
            'post' => $post,
            'comments' => $comments,
            'adminEditUrl' => '/admin/posts/edit/' . $post->id,
        ]);
    }

    public function addComment(string $slug): void
    {
        $postService = $this->container->get(PostService::class);
        $post = $postService->getPublishedBySlug($slug);
        if ($post === null) {
            $this->redirect('/blog');
            return;
        }

        $autoApprove = $this->container->get(SettingRepositoryPort::class)->get('auto_approve_comments', '1') === '1';
        $this->container->get(CommentService::class)->addComment('post', (int) $post->id, $_POST, $autoApprove);

        $this->flash('success', $autoApprove ? 'Đã đăng bình luận của bạn!' : 'Bình luận đã được gửi và đang chờ duyệt.');
        $this->redirect('/post/' . $post->slug . '#comments');
    }
}
