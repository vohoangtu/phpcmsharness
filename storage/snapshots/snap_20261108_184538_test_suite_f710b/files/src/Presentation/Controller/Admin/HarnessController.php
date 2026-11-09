<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\TuzygentHarnessService;
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
            'snapshots' => $harness->listSnapshots(),
            'gateReport' => $gateReport,
            'continuousState' => $harness->getContinuousState(),
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
        $firstCycle = $harness->runContinuousCycle($autoDiscover);

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
        $cycle = $harness->runContinuousCycle($autoDiscover);

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

}
