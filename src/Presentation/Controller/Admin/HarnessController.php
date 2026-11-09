<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\TuzygentHarnessService;
use App\Application\Service\WorkerService;
use App\Infrastructure\Container\Container;
use App\Presentation\Controller\BaseController;
use App\Presentation\Formatting\HarnessTaskProgressFormatter;
use App\Presentation\View\ViewRenderer;

final class HarnessController extends BaseController
{
    private readonly HarnessTaskProgressFormatter $taskProgressFormatter;

    public function __construct(Container $container, ViewRenderer $view)
    {
        parent::__construct($container, $view);
        $this->taskProgressFormatter = new HarnessTaskProgressFormatter();
    }

    public function index(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $gateReport = $harness->runVerificationGate();
        $tasks = $harness->listTasks();

        $this->view->renderAdmin('admin/harness/index', [
            'pageTitle' => '🤖 Tuzygent AI Harness — Tự phát triển Source Code',
            'tasks' => $tasks,
            'taskProgress' => $this->taskProgressFormatter->format($tasks),
            'agents' => $harness->discoverAgents(),
            'apiConfig' => $harness->getApiConfig(),
            'snapshots' => $harness->listSnapshots(),
            'gateReport' => $gateReport,
            'continuousState' => $harness->getContinuousState(),
        ]);
    }

    public function saveApiConfig(): void
    {
        try {
            $this->container->get(TuzygentHarnessService::class)->saveApiConfig($_POST);
            $this->flash('success', 'Đã lưu cấu hình OpenAI-compatible API.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect('/admin/harness');
    }

    public function createTask(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $taskId = $harness->createTask($_POST);
        try {
            $this->container->get(WorkerService::class)->enqueue('harness_task', ['task_id' => $taskId]);
            $this->flash('success', !empty($_POST['run_immediately']) ? 'Đã xếp task vào Workers; xử lý sẽ bắt đầu nền.' : 'Đã thêm task vào hàng đợi Workers.');
        } catch (\Throwable $e) { $this->flash('error', $e->getMessage()); }

        $this->redirect('/admin/harness');
    }

    public function runTask(string $id): void
    {
        try {
            $this->container->get(WorkerService::class)->enqueue('harness_task', ['task_id' => (int)$id]);
            $this->flash('success', 'Task đã được xếp vào hàng đợi Workers.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect('/admin/harness');
    }

    public function installUpdate(string $id): void
    {
        try {
            $task = $this->container->get(TuzygentHarnessService::class)->installUpdate((int) $id);
            if ($task->status === \App\Domain\Enum\HarnessTaskStatus::COMPLETED) {
                $this->flash('success', sprintf('Đã cài gói cập nhật %s lên website.', $task->taskCode));
            } elseif ($task->status === \App\Domain\Enum\HarnessTaskStatus::ROLLED_BACK) {
                $this->flash('error', sprintf('Cài đặt %s thất bại; mã nguồn và database đã được khôi phục về snapshot.', $task->taskCode));
            } elseif ($task->snapshotId === '') {
                $this->flash('error', sprintf('Không thể cài gói %s; website chưa bị thay đổi. Xem log để biết lý do và tạo lại gói nếu source đã cập nhật.', $task->taskCode));
            } else {
                $this->flash('error', sprintf('Cài đặt %s thất bại; không xác nhận được khôi phục hoàn chỉnh. Hãy xem log trước khi thử lại.', $task->taskCode));
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

    public function toggleContinuous(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $action = (string) ($_POST['action'] ?? 'start');

        if ($action === 'stop') {
            $harness->stopContinuousMode();
            $this->flash('success', '⏹️ Đã dừng chế độ Continuous Autonomous Mode.');
            $this->redirect('/admin/harness');
        }

        $interval = max(1, min(300, (int) ($_POST['interval_seconds'] ?? 5)));
        $autoDiscover = !empty($_POST['auto_discover']);
        $autoHeal = !empty($_POST['auto_heal_on_drift']);
        $spawnDaemon = !empty($_POST['spawn_daemon']);

        $state = $harness->startContinuousMode([
            'interval_seconds' => $interval,
            'auto_discover' => $autoDiscover,
            'auto_heal_on_drift' => $autoHeal,
            'spawn_daemon' => $spawnDaemon,
        ]);

        // Thực thi ngay chu kỳ đầu tiên khi kích hoạt từ Web UI
        $firstCycle = $harness->runContinuousCycle($autoDiscover, true);
        $this->enqueueContinuousTask($firstCycle);

        $this->flash(
            'success',
            sprintf(
                '🟢 Đã bật chế độ Continuous Autonomous Mode (Chu kỳ: %ds | Auto-Discover: %s). %s',
                $state['interval_seconds'],
                $state['auto_discover'] ? 'ON' : 'OFF',
                $firstCycle['summary']
            )
        );
        $this->redirect('/admin/harness');
    }

    public function continuousTick(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $autoDiscover = isset($_POST['auto_discover']) ? !empty($_POST['auto_discover']) : null;
        $cycle = $harness->runContinuousCycle($autoDiscover, true);
        $this->enqueueContinuousTask($cycle);

        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax || !empty($_POST['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'cycle' => $cycle['cycle'],
                'action' => $cycle['action'],
                'gate_passed' => $cycle['gate_passed'],
                'summary' => $cycle['summary'],
                'state' => $cycle['state'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($cycle['gate_passed']) {
            $this->flash('success', '⚡ ' . $cycle['summary']);
        } else {
            $this->flash('error', '⚠️ ' . $cycle['summary']);
        }
        $this->redirect('/admin/harness');
    }

    public function continuousStatus(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $state = $harness->getContinuousState();
        $state['tasks'] = array_values($this->taskProgressFormatter->format(array_slice($harness->listTasks(), 0, 60)));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param array<string, mixed> $cycle */
    private function enqueueContinuousTask(array &$cycle): void
    {
        if (($cycle['action'] ?? '') !== 'queue_task' || !($cycle['task'] ?? null) instanceof \App\Domain\Entity\HarnessTask) return;
        try {
            $workers = $this->container->get(WorkerService::class);
            if (!$workers->hasActiveHarnessTask((int)$cycle['task']->id)) $workers->enqueue('harness_task', ['task_id' => $cycle['task']->id]);
        } catch (\Throwable $e) {
            $cycle['gate_passed'] = false;
            $cycle['summary'] = 'Không thể gửi task vào Workers: ' . $e->getMessage();
        }
    }

}
