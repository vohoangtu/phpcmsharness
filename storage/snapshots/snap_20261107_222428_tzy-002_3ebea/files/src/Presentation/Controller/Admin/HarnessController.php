<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\TuzygentHarnessService;
use App\Presentation\Controller\BaseController;

final class HarnessController extends BaseController
{
    public function index(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $gateReport = $harness->runVerificationGate();

        $this->view->renderAdmin('admin/harness/index', [
            'pageTitle' => '🤖 Tuzygent AI Harness — Tự phát triển Source Code',
            'tasks' => $harness->listTasks(),
            'agents' => $harness->discoverAgents(),
            'snapshots' => $harness->listSnapshots(),
            'gateReport' => $gateReport,
        ]);
    }

    public function createTask(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $taskId = $harness->createTask($_POST);

        if (!empty($_POST['run_immediately'])) {
            $executed = $harness->executeTask($taskId);
            if ($executed->status->value === 'completed') {
                $this->flash('success', sprintf('🎉 Task %s đã tự phát triển code và vượt qua cả 3 Verification Gates thành công!', $executed->taskCode));
            } else {
                $this->flash('error', sprintf('⚠️ Task %s không vượt qua Verification Gate và đã tự động Rollback về Snapshot %s.', $executed->taskCode, $executed->snapshotId));
            }
        } else {
            $this->flash('success', 'Đã thêm Task mới vào Backlog của Tuzygent Harness.');
        }

        $this->redirect('/admin/harness');
    }

    public function runTask(string $id): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        try {
            $executed = $harness->executeTask((int) $id);
            if ($executed->status->value === 'completed') {
                $this->flash('success', sprintf('🚀 Hoàn tất vòng lặp tự phát triển cho %s (%d lần thử) — Tất cả 3 Gates đều PASS!', $executed->taskCode, $executed->attempts));
            } else {
                $this->flash('error', sprintf('⏪ Task %s thất bại sau %d lần thử. Đã tự động Rollback về Snapshot [%s].', $executed->taskCode, $executed->attempts, $executed->snapshotId));
            }
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect('/admin/harness');
    }

    public function verify(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $report = $harness->runVerificationGate();
        if ($report['passed']) {
            $this->flash('success', '✅ Cả 3 Cổng Kiểm Định (Syntax + Hexagonal Purity + Integration Tests) đều PASS 100%!');
        } else {
            $this->flash('error', '❌ Phát hiện lỗi tại Verification Gate. Vui lòng xem chi tiết bên dưới.');
        }
        $this->redirect('/admin/harness');
    }

    public function createSnapshot(): void
    {
        $label = trim((string) ($_POST['label'] ?? 'manual'));
        $snapId = $this->container->get(TuzygentHarnessService::class)->createSnapshot($label);
        $this->flash('success', sprintf('📸 Đã tạo Snapshot mã nguồn thành công: %s', $snapId));
        $this->redirect('/admin/harness');
    }

    public function rollback(): void
    {
        $snapshotId = trim((string) ($_POST['snapshot_id'] ?? ''));
        $ok = $this->container->get(TuzygentHarnessService::class)->rollbackToSnapshot($snapshotId);
        if ($ok) {
            $this->flash('success', sprintf('⏪ Đã khôi phục (Rollback) toàn bộ source code về Snapshot [%s] thành công!', $snapshotId));
        } else {
            $this->flash('error', 'Không tìm thấy hoặc không thể khôi phục Snapshot này.');
        }
        $this->redirect('/admin/harness');
    }

    public function deleteTask(string $id): void
    {
        $this->container->get(TuzygentHarnessService::class)->deleteTask((int) $id);
        $this->flash('success', 'Đã xóa Task khỏi lịch sử Harness.');
        $this->redirect('/admin/harness');
    }
}
