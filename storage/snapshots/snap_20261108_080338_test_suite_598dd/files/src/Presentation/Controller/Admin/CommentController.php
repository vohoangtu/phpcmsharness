<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\CommentService;
use App\Domain\Enum\CommentStatus;
use App\Presentation\Controller\BaseController;

final class CommentController extends BaseController
{
    public function index(): void
    {
        $commentService = $this->container->get(CommentService::class);
        $statusStr = (string) ($_GET['status'] ?? '');
        $status = $statusStr !== '' ? CommentStatus::tryFrom($statusStr) : null;
        $type = (string) ($_GET['type'] ?? '');
        $type = in_array($type, ['post', 'product'], true) ? $type : '';
        $pageSize = 20;
        $totalItems = $commentService->countComments($status, $type);
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $currentPage = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);

        $this->view->renderAdmin('admin/comments/index', [
            'pageTitle' => 'Quản lý Bình luận & Đánh giá',
            'comments' => $commentService->listComments($status, $type, $pageSize, ($currentPage - 1) * $pageSize),
            'counts' => $commentService->getStatusCounts(),
            'currentStatus' => $statusStr,
            'currentType' => $type,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'pageSize' => $pageSize,
        ]);
    }

    public function changeStatus(string $id): void
    {
        $statusStr = (string) ($_POST['status'] ?? 'approved');
        $status = CommentStatus::tryFrom($statusStr) ?? CommentStatus::APPROVED;
        $this->container->get(CommentService::class)->changeStatus((int) $id, $status);
        $this->flash('success', 'Đã cập nhật trạng thái bình luận.');
        $this->redirect('/admin/comments');
    }

    public function delete(string $id): void
    {
        $this->container->get(CommentService::class)->delete((int) $id);
        $this->flash('success', 'Đã xóa bình luận.');
        $this->redirect('/admin/comments');
    }
}
