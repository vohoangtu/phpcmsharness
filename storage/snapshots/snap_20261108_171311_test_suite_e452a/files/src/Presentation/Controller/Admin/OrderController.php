<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\OrderQueryService;
use App\Application\Service\OrderService;
use App\Domain\Enum\OrderStatus;
use App\Presentation\Controller\BaseController;

final class OrderController extends BaseController
{
    public function index(): void
    {
        $orderService = $this->container->get(OrderQueryService::class);
        $statusStr = (string) ($_GET['status'] ?? '');
        $status = $statusStr !== '' ? OrderStatus::tryFrom($statusStr) : null;
        $statusStr = $status?->value ?? '';
        $query = substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $pageSize = 20;
        $totalItems = $orderService->countOrders($status, $query);
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $currentPage = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);

        $this->view->renderAdmin('admin/orders/index', [
            'pageTitle' => 'Quản lý Đơn hàng (Orders)',
            'orders' => $orderService->listOrders($status, $pageSize, ($currentPage - 1) * $pageSize, $query),
            'stats' => $orderService->getSummaryStats(),
            'currentStatus' => $statusStr,
            'searchQuery' => $query,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'pageSize' => $pageSize,
        ]);
    }

    public function detail(string $id): void
    {
        $order = $this->container->get(OrderQueryService::class)->getOrderById((int) $id);
        if ($order === null) {
            $this->flash('error', 'Không tìm thấy đơn hàng.');
            $this->redirect('/admin/orders');
            return;
        }

        $this->view->renderAdmin('admin/orders/detail', [
            'pageTitle' => 'Chi tiết Đơn hàng #' . $order->orderCode,
            'order' => $order,
        ]);
    }

    public function updateStatus(string $id): void
    {
        $statusStr = (string) ($_POST['status'] ?? 'pending');
        $status = OrderStatus::tryFrom($statusStr) ?? OrderStatus::PENDING;
        try {
            $this->container->get(OrderService::class)->updateOrderStatus((int) $id, $status);
            $this->flash('success', 'Đã cập nhật trạng thái đơn hàng thành công!');
        } catch (\RuntimeException $error) {
            $this->flash('error', $error->getMessage());
        }
        $this->redirect('/admin/orders/' . (int) $id);
    }
}
