<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\OrderQueryService;
use App\Application\Service\OrderService;
use App\Domain\Enum\OrderStatus;
use App\Domain\Port\Outbound\OrderOperationsPort;
use App\Domain\Port\Outbound\AuthenticationSessionPort;
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
            'operations' => $this->container->get(OrderOperationsPort::class)->details((int) $id),
        ]);
    }

    public function updateStatus(string $id): void
    {
        $statusStr = (string) ($_POST['status'] ?? 'pending');
        $status = OrderStatus::tryFrom($statusStr);
        try {
            if ($status === null) throw new \RuntimeException('Trạng thái đơn không hợp lệ.');
            $this->container->get(OrderService::class)->updateOrderStatus((int) $id, $status, $this->actor());
            $this->flash('success', 'Đã cập nhật trạng thái đơn hàng thành công!');
        } catch (\RuntimeException $error) {
            $this->flash('error', $error->getMessage());
        }
        $this->redirect('/admin/orders/' . (int) $id);
    }

    public function operation(string $id): void
    {
        try {
            $operations = $this->container->get(OrderOperationsPort::class);
            $data = $_POST; $actor = $this->actor();
            match ((string) ($data['operation'] ?? '')) {
                'payment' => $operations->recordPayment((int) $id, $data, $actor),
                'shipment' => $operations->saveShipment((int) $id, $data, $actor),
                'invoice' => $operations->saveInvoice((int) $id, $data, $actor),
                'adjustment' => $operations->addAdjustment((int) $id, $data, $actor),
                default => throw new \RuntimeException('Thao tác không hợp lệ.'),
            };
            $this->flash('success', 'Đã lưu thông tin đơn hàng.');
        } catch (\Throwable $e) { $this->flash('error', $e instanceof \RuntimeException ? $e->getMessage() : 'Không thể lưu thông tin đơn hàng.'); }
        $this->redirect('/admin/orders/' . (int) $id);
    }

    public function printOrder(string $id): void
    {
        $order = $this->container->get(OrderQueryService::class)->getOrderById((int) $id);
        if ($order === null) { http_response_code(404); echo 'Không tìm thấy đơn hàng.'; return; }
        $operations = $this->container->get(OrderOperationsPort::class)->details((int) $id);
        require dirname(__DIR__, 2) . '/Views/admin/orders/print.php';
    }

    public function printPaymentDocument(string $id): void
    {
        $document = $this->container->get(OrderOperationsPort::class)->paymentDocument((int) $id);
        if ($document === null) { http_response_code(404); echo 'Không tìm thấy giao dịch.'; return; }
        require dirname(__DIR__, 2) . '/Views/admin/orders/payment-document.php';
    }

    public function cashbook(): void
    {
        $kind = in_array((string) ($_GET['kind'] ?? ''), ['payment', 'refund'], true) ? (string) $_GET['kind'] : '';
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $from = $this->validDate((string) ($_GET['from'] ?? ''));
        $to = $this->validDate((string) ($_GET['to'] ?? ''));
        $pageSize = 25;
        $operations = $this->container->get(OrderOperationsPort::class);
        $result = $operations->paymentLedger($kind, $query, $from, $to, $pageSize, 0);
        $total = $result['summary']['total'];
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
        if ($page > 1) $result = $operations->paymentLedger($kind, $query, $from, $to, $pageSize, ($page - 1) * $pageSize);
        $this->view->renderAdmin('admin/orders/cashbook', [
            'pageTitle' => 'Sổ thu chi', 'rows' => $result['rows'], 'summary' => $result['summary'],
            'kind' => $kind, 'query' => $query, 'from' => $from, 'to' => $to,
            'page' => $page, 'pageSize' => $pageSize, 'totalPages' => $totalPages,
        ]);
    }

    private function validDate(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return '';
        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : '';
    }

    private function actor(): string
    {
        $principal = $this->container->get(AuthenticationSessionPort::class)->currentPrincipal();
        return (string) ($principal['username'] ?? 'system');
    }
}
