<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\MediaService;
use App\Application\Service\PostService;
use App\Application\Service\TaxonomyService;
use App\Domain\Enum\ContentStatus;
use App\Presentation\Controller\BaseController;

final class PostController extends BaseController
{
    public function index(): void
    {
        $postService = $this->container->get(PostService::class);
        $taxonomyService = $this->container->get(TaxonomyService::class);

        $statusStr = (string) ($_GET['status'] ?? '');
        $status = $statusStr !== '' ? ContentStatus::tryFrom($statusStr) : null;
        $categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
        $search = trim((string) ($_GET['s'] ?? ''));

        $this->view->renderAdmin('admin/posts/index', [
            'pageTitle' => 'Quản lý Bài viết (Posts)',
            'posts' => $postService->listPosts($status, $categoryId, $search),
            'counts' => $postService->getStatusCounts(),
            'categories' => $taxonomyService->getCategories('post'),
            'currentStatus' => $statusStr,
            'currentCategory' => $categoryId,
            'search' => $search,
        ]);
    }

    public function create(): void
    {
        $this->renderForm(null);
    }

    public function edit(string $id): void
    {
        $post = $this->container->get(PostService::class)->getById((int) $id);
        if ($post === null) {
            $this->flash('error', 'Không tìm thấy bài viết.');
            $this->redirect('/admin/posts');
            return;
        }
        $this->renderForm($post);
    }

    public function save(): void
    {
        $postService = $this->container->get(PostService::class);
        $mediaService = $this->container->get(MediaService::class);

        $data = $_POST;
        if (!empty($_FILES['featured_image_file']['tmp_name'])) {
            try {
                $uploaded = $mediaService->upload($_FILES['featured_image_file'], (string) ($data['title'] ?? ''));
                $data['featured_image'] = $uploaded->filePath;
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage());
            }
        }

        $authorId = (int) ($_SESSION['admin_user']['id'] ?? 1);
        $savedId = $postService->savePost($data, $authorId);

        $this->flash('success', 'Đã lưu bài viết thành công!');
        $this->redirect('/admin/posts/edit/' . $savedId);
    }

    public function changeStatus(string $id): void
    {
        $statusStr = (string) ($_POST['status'] ?? 'trash');
        $status = ContentStatus::tryFrom($statusStr) ?? ContentStatus::TRASH;
        $this->container->get(PostService::class)->changeStatus((int) $id, $status);
        $this->flash('success', 'Đã cập nhật trạng thái bài viết.');
        $this->redirect('/admin/posts');
    }

    public function delete(string $id): void
    {
        $this->container->get(PostService::class)->deletePermanently((int) $id);
        $this->flash('success', 'Đã xóa vĩnh viễn bài viết.');
        $this->redirect('/admin/posts?status=trash');
    }

    private function renderForm(mixed $post): void
    {
        $taxonomyService = $this->container->get(TaxonomyService::class);
        $mediaService = $this->container->get(MediaService::class);

        $this->view->renderAdmin('admin/posts/form', [
            'pageTitle' => $post ? 'Chỉnh sửa Bài viết' : 'Thêm Bài viết mới',
            'post' => $post,
            'categories' => $taxonomyService->getCategories('post'),
            'mediaFiles' => $mediaService->listAll(),
        ]);
    }
}
