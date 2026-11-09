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

        $this->view->renderAdmin('admin/comments/index', [
            'pageTitle' => 'Quản lý Bình luận & Đánh giá',
            'comments' => $commentService->listComments($status, $type),
            'counts' => $commentService->getStatusCounts(),
            'currentStatus' => $statusStr,
            'currentType' => $type,
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
