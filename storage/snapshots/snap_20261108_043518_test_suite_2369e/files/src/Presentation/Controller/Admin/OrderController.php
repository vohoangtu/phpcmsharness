<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\OrderService;
use App\Domain\Enum\OrderStatus;
use App\Presentation\Controller\BaseController;

final class OrderController extends BaseController
{
    public function index(): void
    {
        $orderService = $this->container->get(OrderService::class);
        $statusStr = (string) ($_GET['status'] ?? '');
        $status = $statusStr !== '' ? OrderStatus::tryFrom($statusStr) : null;

        $this->view->renderAdmin('admin/orders/index', [
            'pageTitle' => 'Quản lý Đơn hàng (Orders)',
            'orders' => $orderService->listOrders($status),
            'stats' => $orderService->getSummaryStats(),
            'currentStatus' => $statusStr,
        ]);
    }

    public function detail(string $id): void
    {
        $order = $this->container->get(OrderService::class)->getOrderById((int) $id);
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
        $this->container->get(OrderService::class)->updateOrderStatus((int) $id, $status);

        $this->flash('success', 'Đã cập nhật trạng thái đơn hàng thành công!');
        $this->redirect('/admin/orders/' . (int) $id);
    }
}
