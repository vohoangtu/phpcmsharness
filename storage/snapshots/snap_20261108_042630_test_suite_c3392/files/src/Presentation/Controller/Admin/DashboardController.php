<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\CommentService;
use App\Application\Service\ModuleManagerService;
use App\Application\Service\OrderService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Domain\Enum\ContentStatus;
use App\Presentation\Controller\BaseController;

final class DashboardController extends BaseController
{
    public function index(): void
    {
        $moduleManager = $this->container->get(ModuleManagerService::class);
        $postService = $this->container->get(PostService::class);
        $productService = $this->container->get(ProductService::class);
        $orderService = $this->container->get(OrderService::class);
        $commentService = $this->container->get(CommentService::class);

        $this->view->renderAdmin('admin/dashboard', [
            'pageTitle' => 'Bảng điều khiển (Dashboard)',
            'modules' => $moduleManager->getAllModules(),
            'postCounts' => $postService->getStatusCounts(),
            'productCounts' => $productService->getStatusCounts(),
            'orderStats' => $orderService->getSummaryStats(),
            'commentCounts' => $commentService->getStatusCounts(),
            'recentPosts' => $postService->listPosts(null, null, '', 5),
            'recentProducts' => $productService->listProducts(null, null, '', null, 5),
            'recentOrders' => array_slice($orderService->listOrders(), 0, 5),
        ]);
    }

    public function quickDraft(): void
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($title !== '') {
            $userId = (int) ($_SESSION['admin_user']['id'] ?? 1);
            $this->container->get(PostService::class)->savePost([
                'title' => $title,
                'excerpt' => mb_substr(strip_tags($content), 0, 140),
                'content' => '<p>' . nl2br(htmlspecialchars($content)) . '</p>',
                'status' => ContentStatus::DRAFT->value,
            ], $userId);
            $this->flash('success', 'Đã lưu bản nháp nhanh thành công!');
        }
        $this->redirect('/admin');
    }
}
