<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\WorkerService;
use App\Infrastructure\Container\Container;
use App\Presentation\Controller\BaseController;
use App\Presentation\View\ViewRenderer;

final class WorkersController extends BaseController
{
    public function __construct(Container $container, ViewRenderer $view) { parent::__construct($container, $view); }

    public function index(): void
    {
        $worker = $this->container->get(WorkerService::class);
        $this->view->renderAdmin('admin/workers/index', ['pageTitle'=>'Workers — Hàng đợi tác vụ', 'jobs'=>$worker->listJobs(), 'workerStatus'=>$worker->status()]);
    }

    public function retry(string $id): void
    {
        try { $ok = $this->container->get(WorkerService::class)->retry((int)$id); }
        catch (\Throwable $e) { $this->flash('error', $e->getMessage()); $this->redirect('/admin/workers'); return; }
        $this->flash($ok ? 'success' : 'error', $ok ? 'Đã đưa job lỗi trở lại hàng đợi.' : 'Chỉ có thể retry job đang failed.');
        $this->redirect('/admin/workers');
    }

    public function cancel(string $id): void
    {
        $ok = $this->container->get(WorkerService::class)->cancel((int)$id);
        $this->flash($ok ? 'success' : 'error', $ok ? 'Đã hủy job đang chờ.' : 'Job đã bắt đầu hoặc không tồn tại; không thể hủy.');
        $this->redirect('/admin/workers');
    }
}
