<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\TuzygentHarnessService;
use App\Domain\Entity\HarnessTask;
use App\Presentation\Controller\BaseController;

final class HarnessController extends BaseController
{
    public function index(): void
    {
        $harness = $this->container->get(TuzygentHarnessService::class);
        $gateReport = $harness->runVerificationGate();
        $tasks = $harness->listTasks();

        $this->view->renderAdmin('admin/harness/index', [
            'pageTitle' => '🤖 Tuzygent AI Harness — Tự phát triển Source Code',
            'tasks' => $tasks,
            'taskProgress' => $this->formatTaskProgress($tasks),
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
        $state['tasks'] = array_values($this->formatTaskProgress(array_slice($harness->listTasks(), 0, 60)));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param HarnessTask[] $tasks
     * @return array<int, array{id: int, status: string, status_label: string, badge_class: string, attempts: int, max_attempts: int, summary: string, files_changed: string[], snapshot_id: string}>
     */
    private function formatTaskProgress(array $tasks): array
    {
        $formatted = [];
        foreach ($tasks as $task) {
            $files = $task->filesChanged;
            $summary = match ($task->status->value) {
                'backlog' => 'Đang chờ được thực thi.',
                'running' => $this->latestProgressLine($task->executionLog)
                    ?? ($files !== [] ? sprintf('Đang làm việc trên %d tệp.', count($files)) : 'Agent đang phân tích yêu cầu.'),
                'completed' => sprintf('Hoàn tất; đã cập nhật %d tệp và vượt qua 3/3 cổng kiểm định.', count($files)),
                'rolled_back' => sprintf('Không hoàn tất sau %d lần thử; source đã được khôi phục về snapshot.', $task->attempts),
                default => 'Tác vụ chưa hoàn tất.',
            };
            $formatted[(int) $task->id] = [
                'id' => (int) $task->id,
                'status' => $task->status->value,
                'status_label' => $task->status->label(),
                'badge_class' => $task->status->badgeClass(),
                'attempts' => $task->attempts,
                'max_attempts' => $task->maxAttempts,
                'summary' => $summary,
                'files_changed' => $files,
                'snapshot_id' => $task->snapshotId,
            ];
        }
        return $formatted;
    }

    private function latestProgressLine(string $executionLog): ?string
    {
        $lines = array_reverse(explode("\n", $executionLog));
        foreach ($lines as $line) {
            if (!preg_match('/^\[\d{2}:\d{2}:\d{2}\] (?:🔧|🧪|✅|📸|🔄|🚀)\s*(.*)$/u', trim($line), $matches)) {
                continue;
            }
            return trim($matches[1]);
        }
        return null;
    }
}
