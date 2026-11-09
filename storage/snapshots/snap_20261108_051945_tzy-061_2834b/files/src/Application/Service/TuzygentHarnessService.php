<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\HarnessTask;
use App\Domain\Enum\HarnessTaskStatus;
use App\Domain\Port\Outbound\HarnessRepositoryPort;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class TuzygentHarnessService
{
    private string $snapshotsDir;
    private string $harnessDir;
    private string $continuousStateFile;

    public function __construct(
        private HarnessRepositoryPort $harnessRepository,
        private string $basePath
    ) {
        $this->snapshotsDir = $this->basePath . '/storage/snapshots';
        if (!is_dir($this->snapshotsDir)) {
            mkdir($this->snapshotsDir, 0775, true);
        }
        $this->harnessDir = $this->basePath . '/.harness';
        if (!is_dir($this->harnessDir)) {
            mkdir($this->harnessDir, 0775, true);
        }
        $this->continuousStateFile = $this->harnessDir . '/continuous_state.json';
    }

    /**
     * @return HarnessTask[]
     */
    public function listTasks(): array
    {
        return $this->harnessRepository->findAll();
    }

    public function getTask(int $id): ?HarnessTask
    {
        return $this->harnessRepository->findById($id);
    }

    /**
     * Phát hiện các AI Agent CLI có sẵn trên máy cùng bộ Built-in Hexagonal Generator.
     *
     * @return array<string, array{name: string, binary: string, available: bool, description: string}>
     */
    public function discoverAgents(): array
    {
        $candidates = [
            'builtin-hexagonal' => [
                'name' => 'Tuzygent Hexagonal Engine (Built-in)',
                'binary' => 'php',
                'description' => 'Bộ sinh mã nguồn chuẩn Hexagonal Architecture (Entity + Port + SQLite Adapter + Service + Test) tích hợp sẵn.',
            ],
            'claude' => [
                'name' => 'Claude Code CLI',
                'binary' => 'claude',
                'description' => 'Anthropic Claude Agent CLI tự động phân tích và sửa source code.',
            ],
            'codex' => [
                'name' => 'OpenAI Codex CLI',
                'binary' => 'codex',
                'description' => 'OpenAI Codex CLI hỗ trợ sinh patch và tự động viết test.',
            ],
            'gemini' => [
                'name' => 'Google Gemini CLI',
                'binary' => 'gemini',
                'description' => 'Google Gemini CLI chạy vòng lặp phát triển tính năng.',
            ],
            'opencode' => [
                'name' => 'OpenCode Agent CLI',
                'binary' => 'opencode',
                'description' => 'OpenCode CLI hỗ trợ lập trình tự hành trên terminal.',
            ],
            'cursor-agent' => [
                'name' => 'Cursor Agent CLI',
                'binary' => 'cursor-agent',
                'description' => 'Cursor Agent CLI thực thi tác vụ đa tệp.',
            ],
        ];

        $result = [];
        foreach ($candidates as $key => $info) {
            $available = $key === 'builtin-hexagonal' || $this->isBinaryAvailable($info['binary']);
            $result[$key] = [
                'name' => $info['name'],
                'binary' => $info['binary'],
                'available' => $available,
                'description' => $info['description'],
            ];
        }

        return $result;
    }

    /**
     * Tạo nhiệm vụ (Task) mới trong Backlog của Tuzygent Harness.
     *
     * @param array<string, mixed> $data
     */
    public function createTask(array $data): int
    {
        $all = $this->harnessRepository->findAll();
        $nextNum = count($all) + 1;
        $taskCode = sprintf('TZY-%03d', $nextNum);

        $actionType = in_array(($data['action_type'] ?? ''), ['scaffold_module', 'agent_prompt', 'research_and_develop', 'self_audit'], true)
            ? (string) $data['action_type']
            : 'scaffold_module';

        $agentBackend = trim((string) ($data['agent_backend'] ?? 'builtin-hexagonal'));
        if (!in_array($agentBackend, ['builtin-hexagonal', 'claude', 'codex', 'gemini', 'opencode', 'cursor-agent'], true)) {
            $agentBackend = 'builtin-hexagonal';
        }

        $targetName = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($data['target_name'] ?? ''));
        if ($actionType === 'scaffold_module' && $targetName === '') {
            $targetName = 'Feature' . $nextNum;
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $actionType === 'scaffold_module'
                ? "Tự động phát triển phân hệ Hexagonal: {$targetName}"
                : "Tác vụ tự phát triển {$taskCode}";
        }

        $task = new HarnessTask(
            id: null,
            taskCode: $taskCode,
            title: $title,
            specification: trim((string) ($data['specification'] ?? '')),
            actionType: $actionType,
            targetName: $targetName,
            agentBackend: $agentBackend,
            status: HarnessTaskStatus::BACKLOG,
            attempts: 0,
            maxAttempts: max(1, min(5, (int) ($data['max_attempts'] ?? 3))),
            snapshotId: ''
        );

        return $this->harnessRepository->save($task);
    }

    public function deleteTask(int $id): void
    {
        $this->harnessRepository->delete($id);
    }

    /**
     * Chạy 3 cổng kiểm định chất lượng (3-Stage Verification Gate):
     * 1. PHP 8.5 Syntax Lint (php -l)
     * 2. Hexagonal Architecture Purity Gate (Domain không phụ thuộc PDO/HTTP/Infrastructure)
     * 3. Automated Integration & Regression Tests (tests/hexagon_test.php)
     *
     * @return array{passed: bool, gates: array<string, array{passed: bool, title: string, details: string}>, summary: string}
     */
    public function runVerificationGate(): array
    {
        $gates = [];

        // GATE 1: PHP 8.5 Syntax Lint (In-process TOKEN_PARSE AST validation)
        $phpFiles = $this->collectPhpFiles(['config', 'src', 'public', 'tests']);
        $syntaxErrors = [];
        foreach ($phpFiles as $file) {
            $relPath = str_replace($this->basePath . '/', '', $file);
            $code = (string) file_get_contents($file);
            try {
                token_get_all($code, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $syntaxErrors[] = sprintf('[%s:%d] ParseError: %s', $relPath, $e->getLine(), $e->getMessage());
            }
        }
        $gate1Passed = empty($syntaxErrors);
        $gates['syntax'] = [
            'passed' => $gate1Passed,
            'title' => 'Gate 1: PHP 8.5 AST Syntax Check (TOKEN_PARSE)',
            'details' => $gate1Passed
                ? sprintf('Đã phân tích cây cú pháp AST trên %d tệp PHP — 0 lỗi cú pháp.', count($phpFiles))
                : implode("\n", $syntaxErrors),
        ];

        // GATE 2: Hexagonal Architecture Purity Check
        $domainFiles = $this->collectPhpFiles(['src/Domain']);
        $archViolations = [];
        foreach ($domainFiles as $file) {
            $content = (string) file_get_contents($file);
            $relPath = str_replace($this->basePath . '/', '', $file);

            if (!str_contains($content, 'declare(strict_types=1);')) {
                $archViolations[] = "[{$relPath}] Thiếu khai báo declare(strict_types=1);";
            }
            $forbiddenTokens = [
                'use PDO' => 'Không được phụ thuộc trực tiếp vào PDO trong tầng Domain',
                'App\\Infrastructure' => 'Tầng Domain không được phụ thuộc ngược vào Infrastructure',
                'App\\Presentation' => 'Tầng Domain không được phụ thuộc ngược vào Presentation',
                '$_POST' => 'Tầng Domain không được truy cập biến toàn cục $_POST',
                '$_SESSION' => 'Tầng Domain không được truy cập biến toàn cục $_SESSION',
            ];
            foreach ($forbiddenTokens as $token => $reason) {
                if (str_contains($content, $token)) {
                    $archViolations[] = "[{$relPath}] Vi phạm Hexagonal: {$reason} ({$token})";
                }
            }
        }
        $gate2Passed = empty($archViolations);
        $gates['hexagonal_purity'] = [
            'passed' => $gate2Passed,
            'title' => 'Gate 2: Hexagonal Architecture & Clean Code Purity',
            'details' => $gate2Passed
                ? sprintf('Đã kiểm toán %d tệp trong src/Domain — 100%% tuân thủ strict_types=1 & cô lập hoàn toàn khỏi Infrastructure/HTTP.', count($domainFiles))
                : implode("\n", $archViolations),
        ];

        // GATE 3: Integration & Regression Tests
        $phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $testFile = $this->basePath . '/tests/hexagon_test.php';
        $testOut = [];
        $testCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($testFile)), $testOut, $testCode);
        $gate3Passed = ($testCode === 0);
        $gates['integration_tests'] = [
            'passed' => $gate3Passed,
            'title' => 'Gate 3: Automated Integration & Regression Test Suite',
            'details' => implode("\n", $testOut),
        ];

        $allPassed = $gate1Passed && $gate2Passed && $gate3Passed;
        $summaryLines = [];
        foreach ($gates as $g) {
            $statusIcon = $g['passed'] ? '✅ PASS' : '❌ FAIL';
            $summaryLines[] = "{$statusIcon} | {$g['title']}\n" . $g['details'];
        }

        return [
            'passed' => $allPassed,
            'gates' => $gates,
            'summary' => implode("\n----------------------------------------\n", $summaryLines),
        ];
    }

    /**
     * Thực thi Vòng lặp Tự phát triển (Autonomous Self-Evolving Loop):
     * Snapshot -> Propose/Generate Code -> Verification Gate -> Self-Heal Retry -> Commit or Rollback.
     */
    public function executeTask(int $taskId): HarnessTask
    {
        $task = $this->harnessRepository->findById($taskId);
        if ($task === null) {
            throw new RuntimeException("Không tìm thấy Task #{$taskId}");
        }

        $logs = [];
        $logs[] = sprintf('[%s] 🚀 Bắt đầu vòng lặp Tuzygent Autonomous Loop cho Task %s: %s', date('H:i:s'), $task->taskCode, $task->title);

        // 1. Tạo Snapshot trước khi sửa source code
        $snapshotId = $this->createSnapshot($task->taskCode);
        $logs[] = sprintf('[%s] 📸 Đã tạo Snapshot an toàn: %s', date('H:i:s'), $snapshotId);

        $attempt = 0;
        $maxAttempts = max(1, $task->maxAttempts);
        $lastGateResult = null;
        $filesChanged = [];
        $previousFailureFeedback = '';

        while ($attempt < $maxAttempts) {
            $attempt++;
            $logs[] = sprintf('[%s] 🔄 Lần thử #%d/%d với Agent Backend [%s]...', date('H:i:s'), $attempt, $maxAttempts, $task->agentBackend);

            $proposalSucceeded = false;
            try {
                $changedInStep = $this->runAgentProposalStep($task, $previousFailureFeedback, $logs);
                $filesChanged = array_values(array_unique(array_merge($filesChanged, $changedInStep)));
                $proposalSucceeded = true;
            } catch (\Throwable $e) {
                $logs[] = sprintf('[%s] ⚠️ Lỗi trong bước sinh code: %s', date('H:i:s'), $e->getMessage());
                $previousFailureFeedback = 'Proposal step failed: ' . $e->getMessage();
            }

            // Chạy Verification Gate
            $logs[] = sprintf('[%s] 🧪 Đang chạy 3-Stage Verification Gate...', date('H:i:s'));
            $lastGateResult = $this->runVerificationGate();

            if ($proposalSucceeded && $lastGateResult['passed']) {
                $logs[] = sprintf('[%s] ✅ TẤT CẢ 3 VERIFICATION GATES ĐỀU VƯỢT QUA! Chấp nhận thay đổi (Admission Passed).', date('H:i:s'));
                $completedTask = new HarnessTask(
                    id: $task->id,
                    taskCode: $task->taskCode,
                    title: $task->title,
                    specification: $task->specification,
                    actionType: $task->actionType,
                    targetName: $task->targetName,
                    agentBackend: $task->agentBackend,
                    status: HarnessTaskStatus::COMPLETED,
                    attempts: $attempt,
                    maxAttempts: $maxAttempts,
                    snapshotId: $snapshotId,
                    filesChanged: $filesChanged,
                    verificationOutput: $lastGateResult['summary'],
                    executionLog: implode("\n", $logs)
                );
                $this->harnessRepository->save($completedTask);
                return $this->harnessRepository->findById((int) $task->id) ?? $completedTask;
            }

            $previousFailureFeedback = $lastGateResult['summary'];
            $logs[] = sprintf('[%s] ❌ Verification Gate thất bại ở lần thử #%d. Kích hoạt cơ chế Self-Healing...', date('H:i:s'), $attempt);
        }

        // Nếu vượt quá số lần thử tối đa mà vẫn lỗi -> Tự động Rollback về Snapshot!
        $logs[] = sprintf('[%s] ⏪ Vượt quá %d lần thử. Tự động Rollback mã nguồn về Snapshot [%s] để bảo vệ hệ thống!', date('H:i:s'), $maxAttempts, $snapshotId);
        $this->rollbackToSnapshot($snapshotId);

        $rolledBackTask = new HarnessTask(
            id: $task->id,
            taskCode: $task->taskCode,
            title: $task->title,
            specification: $task->specification,
            actionType: $task->actionType,
            targetName: $task->targetName,
            agentBackend: $task->agentBackend,
            status: HarnessTaskStatus::ROLLED_BACK,
            attempts: $attempt,
            maxAttempts: $maxAttempts,
            snapshotId: $snapshotId,
            filesChanged: $filesChanged,
            verificationOutput: $lastGateResult['summary'] ?? 'Failed verification gate.',
            executionLog: implode("\n", $logs)
        );
        $this->harnessRepository->save($rolledBackTask);

        return $this->harnessRepository->findById((int) $task->id) ?? $rolledBackTask;
    }

    /**
     * Tạo bản sao lưu (Snapshot) của mã nguồn hiện tại trước khi chạy Agent.
     */
    public function createSnapshot(string $label = 'manual'): string
    {
        $cleanLabel = preg_replace('/[^a-zA-Z0-9_-]/', '', $label) ?: 'snap';
        $snapshotId = sprintf('snap_%s_%s_%s', date('Ymd_His'), strtolower($cleanLabel), substr(bin2hex(random_bytes(3)), 0, 5));
        $targetDir = $this->snapshotsDir . '/' . $snapshotId;
        mkdir($targetDir, 0775, true);

        $files = $this->collectSourceFiles(['config', 'src', 'public', 'tests']);
        $manifest = [
            'snapshot_id' => $snapshotId,
            'label' => $label,
            'created_at' => date('Y-m-d H:i:s'),
            'file_scope' => 'project-source-v1',
            'files' => [],
        ];

        foreach ($files as $absPath) {
            $relPath = str_replace($this->basePath . '/', '', $absPath);
            $destPath = $targetDir . '/files/' . $relPath;
            if (!is_dir(dirname($destPath))) {
                mkdir(dirname($destPath), 0775, true);
            }
            copy($absPath, $destPath);
            $manifest['files'][] = $relPath;
        }

        file_put_contents($targetDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $snapshotId;
    }

    /**
     * Khôi phục toàn bộ source code về trạng thái của Snapshot.
     */
    public function rollbackToSnapshot(string $snapshotId): bool
    {
        $safeId = basename($snapshotId);
        $snapDir = $this->snapshotsDir . '/' . $safeId;
        $manifestFile = $snapDir . '/manifest.json';
        if (!is_file($manifestFile)) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (!is_array($manifest) || empty($manifest['files'])) {
            return false;
        }

        $recordedFiles = array_flip($manifest['files']);

        // Snapshot trước file_scope chỉ ghi nhận PHP; giữ nguyên hành vi tương thích.
        $currentFiles = ($manifest['file_scope'] ?? '') === 'project-source-v1'
            ? $this->collectSourceFiles(['config', 'src', 'public', 'tests'])
            : $this->collectPhpFiles(['config', 'src', 'public', 'tests']);
        foreach ($currentFiles as $absPath) {
            $relPath = str_replace($this->basePath . '/', '', $absPath);
            if (!isset($recordedFiles[$relPath])) {
                @unlink($absPath);
            }
        }

        // Khôi phục toàn bộ tệp trong Snapshot
        foreach ($manifest['files'] as $relPath) {
            $srcFile = $snapDir . '/files/' . $relPath;
            $destFile = $this->basePath . '/' . $relPath;
            if (is_file($srcFile)) {
                if (!is_dir(dirname($destFile))) {
                    mkdir(dirname($destFile), 0775, true);
                }
                copy($srcFile, $destFile);
            }
        }

        return true;
    }

    /**
     * @return array<int, array{snapshot_id: string, label: string, created_at: string, file_count: int}>
     */
    public function listSnapshots(): array
    {
        if (!is_dir($this->snapshotsDir)) {
            return [];
        }
        $dirs = scandir($this->snapshotsDir) ?: [];
        $list = [];
        foreach ($dirs as $d) {
            if ($d === '.' || $d === '..') {
                continue;
            }
            $manifestFile = $this->snapshotsDir . '/' . $d . '/manifest.json';
            if (is_file($manifestFile)) {
                $data = json_decode((string) file_get_contents($manifestFile), true);
                if (is_array($data)) {
                    $list[] = [
                        'snapshot_id' => (string) ($data['snapshot_id'] ?? $d),
                        'label' => (string) ($data['label'] ?? ''),
                        'created_at' => (string) ($data['created_at'] ?? ''),
                        'file_count' => is_array($data['files'] ?? null) ? count($data['files']) : 0,
                    ];
                }
            }
        }
        usort($list, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));
        return $list;
    }

    /**
     * Lấy trạng thái hiện tại của chế độ Continuous Autonomous Mode.
     *
     * @return array{
     *     enabled: bool,
     *     status: string,
     *     pid: ?int,
     *     daemon_alive: bool,
     *     interval_seconds: int,
     *     auto_discover: bool,
     *     auto_heal_on_drift: bool,
     *     max_cycles: int,
     *     cycles_completed: int,
     *     tasks_executed: int,
     *     tasks_succeeded: int,
     *     tasks_rolled_back: int,
     *     started_at: string,
     *     last_heartbeat: string,
     *     last_cycle_summary: string,
     *     last_healthy_snapshot: string,
     *     recent_events: string[]
     * }
     */
    public function getContinuousState(): array
    {
        $defaults = [
            'enabled' => false,
            'status' => 'stopped',
            'pid' => null,
            'daemon_alive' => false,
            'interval_seconds' => 5,
            'auto_discover' => true,
            'auto_heal_on_drift' => true,
            'max_cycles' => 0,
            'cycles_completed' => 0,
            'tasks_executed' => 0,
            'tasks_succeeded' => 0,
            'tasks_rolled_back' => 0,
            'started_at' => '',
            'last_heartbeat' => '',
            'last_cycle_summary' => 'Chưa chạy chu kỳ Continuous nào.',
            'last_healthy_snapshot' => '',
            'recent_events' => [],
        ];

        if (!is_file($this->continuousStateFile)) {
            return $defaults;
        }

        $raw = json_decode((string) file_get_contents($this->continuousStateFile), true);
        if (!is_array($raw)) {
            return $defaults;
        }

        $pid = isset($raw['pid']) && is_numeric($raw['pid']) ? (int) $raw['pid'] : null;
        $daemonAlive = $pid !== null && $pid > 0 && is_dir("/proc/{$pid}");
        if (!$daemonAlive) {
            $pid = null;
        }

        $events = [];
        if (is_array($raw['recent_events'] ?? null)) {
            foreach (array_slice($raw['recent_events'], 0, 25) as $ev) {
                $events[] = (string) $ev;
            }
        }

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'status' => (string) ($raw['status'] ?? 'stopped'),
            'pid' => $pid,
            'daemon_alive' => $daemonAlive,
            'interval_seconds' => max(1, min(300, (int) ($raw['interval_seconds'] ?? 5))),
            'auto_discover' => (bool) ($raw['auto_discover'] ?? true),
            'auto_heal_on_drift' => (bool) ($raw['auto_heal_on_drift'] ?? true),
            'max_cycles' => max(0, (int) ($raw['max_cycles'] ?? 0)),
            'cycles_completed' => max(0, (int) ($raw['cycles_completed'] ?? 0)),
            'tasks_executed' => max(0, (int) ($raw['tasks_executed'] ?? 0)),
            'tasks_succeeded' => max(0, (int) ($raw['tasks_succeeded'] ?? 0)),
            'tasks_rolled_back' => max(0, (int) ($raw['tasks_rolled_back'] ?? 0)),
            'started_at' => (string) ($raw['started_at'] ?? ''),
            'last_heartbeat' => (string) ($raw['last_heartbeat'] ?? ''),
            'last_cycle_summary' => (string) ($raw['last_cycle_summary'] ?? $defaults['last_cycle_summary']),
            'last_healthy_snapshot' => (string) ($raw['last_healthy_snapshot'] ?? ''),
            'recent_events' => $events,
        ];
    }

    /**
     * Lưu và cập nhật trạng thái Continuous Mode.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function saveContinuousState(array $patch): array
    {
        $current = $this->getContinuousState();
        $merged = array_merge($current, $patch);
        unset($merged['daemon_alive']);

        if (!is_dir($this->harnessDir)) {
            mkdir($this->harnessDir, 0775, true);
        }
        file_put_contents(
            $this->continuousStateFile,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $this->getContinuousState();
    }

    /**
     * Bật chế độ Continuous Autonomous Mode (hỗ trợ cả Background Daemon CLI & Web Live Polling).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function startContinuousMode(array $options = []): array
    {
        $current = $this->getContinuousState();
        $interval = max(1, min(300, (int) ($options['interval_seconds'] ?? $current['interval_seconds'] ?? 5)));
        $autoDiscover = isset($options['auto_discover']) ? (bool) $options['auto_discover'] : $current['auto_discover'];
        $autoHeal = isset($options['auto_heal_on_drift']) ? (bool) $options['auto_heal_on_drift'] : $current['auto_heal_on_drift'];
        $maxCycles = isset($options['max_cycles']) ? max(0, (int) $options['max_cycles']) : 0;
        $spawnDaemon = (bool) ($options['spawn_daemon'] ?? false);
        $now = date('Y-m-d H:i:s');

        $events = $current['recent_events'];
        array_unshift(
            $events,
            sprintf(
                '[%s] 🚀 Kích hoạt Continuous Mode (Chu kỳ: %ds | Auto-Discover: %s | Auto-Heal: %s)',
                date('H:i:s'),
                $interval,
                $autoDiscover ? 'ON' : 'OFF',
                $autoHeal ? 'ON' : 'OFF'
            )
        );

        $state = $this->saveContinuousState([
            'enabled' => true,
            'status' => 'running',
            'interval_seconds' => $interval,
            'auto_discover' => $autoDiscover,
            'auto_heal_on_drift' => $autoHeal,
            'max_cycles' => $maxCycles,
            'started_at' => $current['started_at'] !== '' ? $current['started_at'] : $now,
            'last_heartbeat' => $now,
            'recent_events' => array_slice($events, 0, 25),
        ]);

        if ($spawnDaemon && !$state['daemon_alive']) {
            $phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $cliScript = $this->basePath . '/bin/tuzygent';
            $logFile = $this->harnessDir . '/continuous_daemon.log';
            $cmd = sprintf(
                'nohup %s %s continuous --interval=%d %s >> %s 2>&1 & echo $!',
                escapeshellarg($phpBin),
                escapeshellarg($cliScript),
                $interval,
                $autoDiscover ? '--auto-discover' : '',
                escapeshellarg($logFile)
            );
            $out = [];
            exec($cmd, $out);
            $spawnedPid = isset($out[0]) && is_numeric(trim($out[0])) ? (int) trim($out[0]) : null;
            if ($spawnedPid !== null && $spawnedPid > 0) {
                $state = $this->saveContinuousState(['pid' => $spawnedPid]);
            }
        }

        return $state;
    }

    /**
     * Dừng chế độ Continuous Autonomous Mode và hủy tiến trình Daemon nền (nếu có).
     *
     * @return array<string, mixed>
     */
    public function stopContinuousMode(bool $killDaemon = true): array
    {
        $current = $this->getContinuousState();
        $myPid = getmypid() ?: null;
        if ($killDaemon && $current['pid'] !== null && $current['pid'] !== $myPid && $current['daemon_alive']) {
            exec(sprintf('kill %d >/dev/null 2>&1', $current['pid']));
        }

        $events = $current['recent_events'];
        array_unshift($events, sprintf('[%s] ⏹️ Đã dừng chế độ Continuous Autonomous Mode.', date('H:i:s')));

        return $this->saveContinuousState([
            'enabled' => false,
            'status' => 'stopped',
            'pid' => null,
            'last_heartbeat' => date('Y-m-d H:i:s'),
            'recent_events' => array_slice($events, 0, 25),
        ]);
    }

    /**
     * Thực thi 1 chu kỳ (Single Tick / Cycle) của chế độ Continuous Autonomous Loop:
     * 1. Tìm Task tiếp theo ở trạng thái BACKLOG (theo thứ tự ưu tiên FIFO).
     * 2. Nếu hết Backlog và bật Auto-Discover -> Tự động phát hiện thực thể/tính năng tiếp theo cần phát triển hoặc tạo Task Self-Audit.
     * 3. Nếu có Task -> Chạy vòng lặp Snapshot -> Generate -> 3-Stage Verification Gate -> Self-Heal / Rollback.
     * 4. Nếu không có Task -> Tuần tra sức khỏe kiến trúc (Continuous Verification & Drift Auto-Heal).
     *
     * @return array{
     *     cycle: int,
     *     action: string,
     *     task: ?HarnessTask,
     *     gate_passed: bool,
     *     summary: string,
     *     state: array<string, mixed>
     * }
     */
    public function runContinuousCycle(?bool $overrideAutoDiscover = null): array
    {
        $state = $this->getContinuousState();
        $cycle = $state['cycles_completed'] + 1;
        $autoDiscover = $overrideAutoDiscover ?? $state['auto_discover'];
        $events = $state['recent_events'];

        // Tìm các Task đang nằm trong Backlog (ưu tiên Task cũ nhất trước - FIFO)
        $allTasks = $this->harnessRepository->findAll();
        $backlogTasks = array_values(array_filter(
            $allTasks,
            static fn(HarnessTask $t): bool => $t->status === HarnessTaskStatus::BACKLOG
        ));
        $backlogTasks = array_reverse($backlogTasks);

        // Nếu hết Backlog và bật chế độ Auto-Discover -> Tự động sinh Task tiến hóa tiếp theo
        if (empty($backlogTasks) && $autoDiscover) {
            $discoveredId = $this->discoverNextEvolutionTask();
            if ($discoveredId !== null) {
                $discoveredTask = $this->harnessRepository->findById($discoveredId);
                if ($discoveredTask !== null) {
                    $backlogTasks[] = $discoveredTask;
                    array_unshift(
                        $events,
                        sprintf('[%s] 🔭 Auto-Discovery: Đã tự động phát hiện & thêm nhiệm vụ %s (%s)', date('H:i:s'), $discoveredTask->taskCode, $discoveredTask->title)
                    );
                }
            }
        }

        // Trường hợp có Task trong Backlog cần thực thi
        if (!empty($backlogTasks)) {
            $nextTask = $backlogTasks[0];
            $executed = $this->executeTask((int) $nextTask->id);
            $passed = ($executed->status === HarnessTaskStatus::COMPLETED);

            $tasksExecuted = $state['tasks_executed'] + 1;
            $tasksSucceeded = $state['tasks_succeeded'] + ($passed ? 1 : 0);
            $tasksRolledBack = $state['tasks_rolled_back'] + ($passed ? 0 : 1);
            $lastHealthySnap = $passed && $executed->snapshotId !== ''
                ? $executed->snapshotId
                : $state['last_healthy_snapshot'];

            $summary = $passed
                ? sprintf('Chu kỳ #%d: Hoàn tất %s (%s) sau %d lần thử — PASS 3/3 Gates!', $cycle, $executed->taskCode, $executed->title, $executed->attempts)
                : sprintf('Chu kỳ #%d: Task %s thất bại & đã Auto-Rollback về Snapshot [%s].', $cycle, $executed->taskCode, $executed->snapshotId);

            array_unshift($events, sprintf('[%s] %s %s', date('H:i:s'), $passed ? '✅' : '⏪', $summary));

            $updatedState = $this->saveContinuousState([
                'cycles_completed' => $cycle,
                'tasks_executed' => $tasksExecuted,
                'tasks_succeeded' => $tasksSucceeded,
                'tasks_rolled_back' => $tasksRolledBack,
                'last_heartbeat' => date('Y-m-d H:i:s'),
                'last_cycle_summary' => $summary,
                'last_healthy_snapshot' => $lastHealthySnap,
                'recent_events' => array_slice($events, 0, 25),
            ]);

            return [
                'cycle' => $cycle,
                'action' => 'executed_task',
                'task' => $executed,
                'gate_passed' => $passed,
                'summary' => $summary,
                'state' => $updatedState,
            ];
        }

        // Trường hợp Backlog trống -> Chạy Continuous Verification & Drift Auto-Healing
        $gateReport = $this->runVerificationGate();
        $gatePassed = $gateReport['passed'];

        if ($gatePassed) {
            $summary = sprintf('Chu kỳ #%d: Tuần tra Continuous Watch — Backlog trống, 3/3 Verification Gates PASS 100%%.', $cycle);
            array_unshift($events, sprintf('[%s] 🛡️ %s', date('H:i:s'), $summary));
        } else {
            if ($state['auto_heal_on_drift'] && $state['last_healthy_snapshot'] !== '') {
                $this->rollbackToSnapshot($state['last_healthy_snapshot']);
                $summary = sprintf('Chu kỳ #%d: Phát hiện lỗi kiến trúc/cú pháp! Đã Auto-Heal khôi phục về Snapshot [%s].', $cycle, $state['last_healthy_snapshot']);
                array_unshift($events, sprintf('[%s] 🚑 %s', date('H:i:s'), $summary));
            } else {
                $summary = sprintf('Chu kỳ #%d: Cảnh báo Verification Gate phát hiện lỗi khi tuần tra Continuous!', $cycle);
                array_unshift($events, sprintf('[%s] ⚠️ %s', date('H:i:s'), $summary));
            }
        }

        $updatedState = $this->saveContinuousState([
            'cycles_completed' => $cycle,
            'last_heartbeat' => date('Y-m-d H:i:s'),
            'last_cycle_summary' => $summary,
            'recent_events' => array_slice($events, 0, 25),
        ]);

        return [
            'cycle' => $cycle,
            'action' => 'health_check',
            'task' => null,
            'gate_passed' => $gatePassed,
            'summary' => $summary,
            'state' => $updatedState,
        ];
    }

    /**
     * Chạy vòng lặp Continuous Autonomous Loop liên tục trên CLI hoặc Worker.
     *
     * @param ?callable(array<string, mixed>): void $onCycle
     * @return array<string, mixed>
     */
    public function runContinuousLoop(
        int $maxCycles = 0,
        int $intervalSeconds = 3,
        bool $autoDiscover = true,
        ?callable $onCycle = null
    ): array {
        $intervalSeconds = max(1, min(300, $intervalSeconds));
        $pid = getmypid() ?: null;

        $this->startContinuousMode([
            'interval_seconds' => $intervalSeconds,
            'auto_discover' => $autoDiscover,
            'max_cycles' => $maxCycles,
            'spawn_daemon' => false,
        ]);
        if ($pid !== null) {
            $this->saveContinuousState(['pid' => $pid]);
        }

        $cyclesRun = 0;
        while (true) {
            $liveState = $this->getContinuousState();
            if (!$liveState['enabled']) {
                break;
            }

            $cycleResult = $this->runContinuousCycle($autoDiscover);
            $cyclesRun++;

            if ($onCycle !== null) {
                $onCycle($cycleResult);
            }

            if ($maxCycles > 0 && $cyclesRun >= $maxCycles) {
                $this->stopContinuousMode();
                break;
            }

            // Ngủ theo từng giây để có thể nhận tín hiệu dừng (Stop) ngay lập tức
            for ($s = 0; $s < $intervalSeconds; $s++) {
                sleep(1);
                $checkState = $this->getContinuousState();
                if (!$checkState['enabled']) {
                    break 2;
                }
            }
        }

        return $this->getContinuousState();
    }

    /**
     * Brainstorm đề tài bằng agent khả dụng hoặc chọn blueprint Hexagonal khi Backlog trống.
     */
    public function discoverNextEvolutionTask(): ?int
    {
        // Không tạo trùng task tự brainstorm nếu một task cùng loại vẫn đang chờ/chạy.
        foreach ($this->harnessRepository->findAll() as $task) {
            if ($task->targetName !== 'AutoTopic' || $task->actionType !== 'research_and_develop') {
                continue;
            }
            if ($task->status === HarnessTaskStatus::BACKLOG || $task->status === HarnessTaskStatus::RUNNING) {
                return null;
            }
        }

        // Auto-Brainstorm chỉ dùng Codex; các backend khác vẫn chọn được cho task thủ công.
        $agentBackend = $this->availableBrainstormBackend();
        if ($agentBackend !== null) {
            return $this->createTask([
                'title' => '[Continuous Auto-Brainstorm] Khám phá và phát triển đề tài tiếp theo',
                'action_type' => 'research_and_develop',
                'target_name' => 'AutoTopic',
                'specification' => <<<'SPEC'
Backlog đang trống và không có đề tài được chỉ định. Hãy tự brainstorm ít nhất 3 ý tưởng cải tiến dựa trên việc khảo sát repository hiện tại, rồi chọn một đề tài có giá trị cao, phù hợp với kiến trúc và phạm vi dự án, đồng thời có thể triển khai an toàn.

Ghi các ý tưởng đã cân nhắc, tiêu chí lựa chọn và đề tài được chọn vào báo cáo nghiên cứu trước khi sửa code. Sau đó nghiên cứu các nguồn đáng tin cậy nếu đề tài cần thông tin bên ngoài, rồi triển khai đề tài đã chọn. Không tự tạo nguồn trích dẫn; nếu không thể truy cập nguồn ngoài thì ghi rõ giới hạn đó.
SPEC,
                'agent_backend' => $agentBackend,
                'max_attempts' => 3,
            ]);
        }

        // Không có coding agent ngoài: tiếp tục dùng các blueprint nội bộ có sẵn.
        $blueprints = [
            'Banner' => 'Tự động sinh phân hệ Quảng cáo & Banner trang chủ (Banner Entity, BannerRepositoryPort, SqliteBannerRepository, BannerService).',
            'Subscriber' => 'Tự động sinh phân hệ Đăng ký nhận bản tin (Subscriber Entity, SubscriberRepositoryPort, SqliteSubscriberRepository, SubscriberService).',
            'Faq' => 'Tự động sinh phân hệ Câu hỏi thường gặp (Faq Entity, FaqRepositoryPort, SqliteFaqRepository, FaqService).',
            'Wishlist' => 'Tự động sinh phân hệ Danh sách yêu thích (Wishlist Entity, WishlistRepositoryPort, SqliteWishlistRepository, WishlistService).',
            'ShippingMethod' => 'Tự động sinh phân hệ Phương thức vận chuyển (ShippingMethod Entity, ShippingMethodRepositoryPort, SqliteShippingMethodRepository, ShippingMethodService).',
            'Affiliate' => 'Tự động sinh phân hệ Tiếp thị liên kết (Affiliate Entity, AffiliateRepositoryPort, SqliteAffiliateRepository, AffiliateService).',
        ];

        $existingTargets = [];
        foreach ($this->harnessRepository->findAll() as $t) {
            if ($t->targetName !== '' && $t->status === HarnessTaskStatus::COMPLETED) {
                $existingTargets[strtolower($t->targetName)] = true;
            }
        }

        foreach ($blueprints as $entityName => $spec) {
            $entityFile = $this->basePath . "/src/Domain/Entity/{$entityName}.php";
            if (!is_file($entityFile) && !isset($existingTargets[strtolower($entityName)])) {
                return $this->createTask([
                    'title' => "[Continuous Auto-Discover] Phát triển phân hệ Hexagonal: {$entityName}",
                    'action_type' => 'scaffold_module',
                    'target_name' => $entityName,
                    'specification' => $spec,
                    'agent_backend' => 'builtin-hexagonal',
                    'max_attempts' => 3,
                ]);
            }
        }

        return null;
    }

    private function availableBrainstormBackend(): ?string
    {
        return $this->isBinaryAvailable('codex') ? 'codex' : null;
    }

    /**
     * Bước đề xuất / sinh code mới của Agent (hỗ trợ Built-in Hexagonal Generator & External CLI Agents).
     *
     * @param string[] $logs
     * @return string[] Danh sách các tệp đã tạo/cập nhật
     */
    private function runAgentProposalStep(HarnessTask $task, string $failureFeedback, array &$logs): array
    {
        if ($task->actionType === 'self_audit') {
            $logs[] = sprintf('[%s] 🔍 Thực hiện Self-Audit kiểm toán toàn bộ kiến trúc Hexagonal.', date('H:i:s'));
            return [];
        }

        if ($task->actionType === 'research_and_develop') {
            if ($task->agentBackend === 'builtin-hexagonal') {
                throw new RuntimeException('Tác vụ nghiên cứu cần chọn một AI Agent CLI (Codex, Claude, Gemini, OpenCode hoặc Cursor).');
            }
            return $this->runExternalAgent($task, $failureFeedback, $logs);
        }

        if ($task->actionType === 'scaffold_module' || $task->agentBackend === 'builtin-hexagonal') {
            $entityName = $this->sanitizePascalCase($task->targetName !== '' ? $task->targetName : 'CustomFeature');
            $logs[] = sprintf('[%s] 🏗️ Built-in Hexagonal Generator đang khởi tạo phân hệ dọc (Vertical Slice) cho thực thể [%s]...', date('H:i:s'), $entityName);
            $generated = $this->scaffoldHexagonalVerticalSlice($entityName, $task->specification);
            $logs[] = sprintf('[%s] 📄 Đã sinh tự động %d tệp theo chuẩn Ports & Adapters.', date('H:i:s'), count($generated));
            return $generated;
        }

        return $this->runExternalAgent($task, $failureFeedback, $logs);
    }

    /**
     * Chạy coding agent ở chế độ headless trong thư mục dự án và thu thập các tệp đã đổi.
     *
     * @param string[] $logs
     * @return string[]
     */
    private function runExternalAgent(HarnessTask $task, string $failureFeedback, array &$logs): array
    {
        $harnessDir = $this->basePath . '/.harness';
        if (!is_dir($harnessDir)) {
            mkdir($harnessDir, 0775, true);
        }
        $promptFile = $harnessDir . '/CURRENT_TASK_PROMPT.md';
        $promptContent = $this->buildAgentPrompt($task, $failureFeedback);
        file_put_contents($promptFile, $promptContent);
        $logs[] = sprintf('[%s] 📝 Đã xuất ngữ cảnh kiến trúc & đặc tả vào .harness/CURRENT_TASK_PROMPT.md', date('H:i:s'));

        $binary = [
            'claude' => 'claude',
            'codex' => 'codex',
            'gemini' => 'gemini',
            'opencode' => 'opencode',
            'cursor-agent' => 'cursor-agent',
        ][$task->agentBackend] ?? null;
        if ($binary === null || !$this->isBinaryAvailable($binary)) {
            throw new RuntimeException("Không tìm thấy CLI Agent '{$task->agentBackend}' trên PATH.");
        }

        $prompt = "Đọc và thực hiện đầy đủ chỉ dẫn trong .harness/CURRENT_TASK_PROMPT.md.\n"
            . "Hãy nghiên cứu đề tài bằng các nguồn hiện có, ghi báo cáo và URL nguồn vào đúng đường dẫn được nêu trong prompt, sau đó trực tiếp triển khai tính năng trong repository.\n"
            . "Chạy ./bin/tuzygent verify khi hoàn tất và sửa các lỗi do thay đổi của bạn gây ra.";
        $command = $this->buildAgentCommand($task->agentBackend, $binary, $prompt);
        $before = $this->captureManagedFileHashes();
        $logs[] = sprintf('[%s] 🤖 Khởi chạy %s ở chế độ tự hành trong %s.', date('H:i:s'), $task->agentBackend, $this->basePath);

        [$exitCode, $output] = $this->runProcess($command, 900);
        $output = trim($output);
        if ($output !== '') {
            $logs[] = '[Agent output]' . "\n" . mb_strimwidth($output, 0, 12000, "\n... (đã rút gọn)");
        }

        if ($task->actionType === 'research_and_develop') {
            $researchDir = $this->harnessDir . '/research';
            if (!is_dir($researchDir)) {
                mkdir($researchDir, 0775, true);
            }
            $researchRel = '.harness/research/' . $task->taskCode . '.md';
            $researchPath = $this->basePath . '/' . $researchRel;
            if (!is_file($researchPath) && $output !== '') {
                file_put_contents($researchPath, "# Research: {$task->title}\n\nAgent output (fallback record):\n\n{$output}\n");
            }
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf('%s kết thúc với exit code %d.%s', $task->agentBackend, $exitCode, $output !== '' ? "\n" . mb_strimwidth($output, 0, 3000, '...') : ''));
        }

        $after = $this->captureManagedFileHashes();
        $changed = [];
        foreach ($after as $relPath => $hash) {
            if (!isset($before[$relPath]) || $before[$relPath] !== $hash) {
                $changed[] = $relPath;
            }
        }
        foreach (array_keys($before) as $relPath) {
            if (!isset($after[$relPath])) {
                $changed[] = $relPath . ' (deleted)';
            }
        }
        sort($changed);
        $implementationChanges = array_values(array_filter(
            $changed,
            static fn(string $path): bool => preg_match('~^(config|src|public|tests)/~', $path) === 1
        ));
        if (empty($implementationChanges)) {
            throw new RuntimeException('Agent kết thúc thành công nhưng không tạo hoặc sửa tệp nào trong phạm vi dự án.');
        }
        $logs[] = sprintf('[%s] 📄 Agent đã thay đổi %d tệp.', date('H:i:s'), count($changed));
        return $changed;
    }

    /** @return string[] */
    private function buildAgentCommand(string $backend, string $binary, string $prompt): array
    {
        return match ($backend) {
            'codex' => [$binary, 'exec', '--sandbox', 'workspace-write', '--cd', $this->basePath, '--approve-for-me', $prompt],
            'claude' => [$binary, '--print', '--dangerously-skip-permissions', '--max-turns', '30', $prompt],
            'gemini' => [$binary, '--approval-mode', 'yolo', '--prompt', $prompt],
            'opencode' => [$binary, 'run', '--dir', $this->basePath, '--auto', $prompt],
            'cursor-agent' => [$binary, '-p', '--force', $prompt],
            default => throw new RuntimeException("Backend không được hỗ trợ: {$backend}"),
        };
    }

    /** @return array{0: int, 1: string} */
    private function runProcess(array $command, int $timeoutSeconds): array
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->basePath);
        if (!is_resource($process)) {
            throw new RuntimeException('Không thể khởi chạy AI Agent CLI.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $startedAt = microtime(true);
        $timedOut = false;
        do {
            $output .= stream_get_contents($pipes[1]) ?: '';
            $output .= stream_get_contents($pipes[2]) ?: '';
            if (strlen($output) > 100000) {
                $output = substr($output, -100000);
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(500000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(100000);
        } while (true);

        $output .= stream_get_contents($pipes[1]) ?: '';
        $output .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedCode = proc_close($process);
        if ($timedOut) {
            throw new RuntimeException("AI Agent vượt quá giới hạn chạy {$timeoutSeconds} giây.\n" . mb_strimwidth($output, 0, 3000, '...'));
        }
        $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $closedCode;
        return [$exitCode, $output];
    }

    /** @return array<string, string> */
    private function captureManagedFileHashes(): array
    {
        $hashes = [];
        foreach (['config', 'src', 'public', 'tests', '.harness/research'] as $dir) {
            $absoluteDir = $this->basePath . '/' . $dir;
            if (!is_dir($absoluteDir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $path = $fileInfo->getRealPath();
                if ($path === false) {
                    continue;
                }
                $relPath = substr($path, strlen($this->basePath) + 1);
                $hashes[$relPath] = hash_file('sha256', $path) ?: '';
            }
        }
        ksort($hashes);
        return $hashes;
    }

    /**
     * Tự động sinh một lát cắt dọc chuẩn Kiến trúc Hexagonal (Domain Entity + Outbound Port + SQLite Adapter + Application Service)
     *
     * @return string[]
     */
    public function scaffoldHexagonalVerticalSlice(string $pascalName, string $description = ''): array
    {
        $pascalName = $this->sanitizePascalCase($pascalName);
        $tableName = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $pascalName)) . 's';
        $createdFiles = [];

        // 1. Domain Entity
        $entityRel = "src/Domain/Entity/{$pascalName}.php";
        $entityContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * Auto-generated by Tuzygent Hexagonal Harness
 * {$description}
 */
final readonly class {$pascalName}
{
    public function __construct(
        public ?int \$id,
        public string \$name,
        public string \$code,
        public bool \$isActive = true,
        public string \$createdAt = ''
    ) {
    }
}

PHP;
        file_put_contents($this->basePath . '/' . $entityRel, $entityContent);
        $createdFiles[] = $entityRel;

        // 2. Domain Outbound Port Interface
        $portRel = "src/Domain/Port/Outbound/{$pascalName}RepositoryPort.php";
        $portContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\Domain\Port\Outbound;

use App\Domain\Entity\\{$pascalName};

/**
 * Outbound Port generated by Tuzygent Hexagonal Harness
 */
interface {$pascalName}RepositoryPort
{
    /**
     * @return {$pascalName}[]
     */
    public function findAll(): array;

    public function findById(int \$id): ?{$pascalName};

    public function save({$pascalName} \$entity): int;

    public function delete(int \$id): void;
}

PHP;
        file_put_contents($this->basePath . '/' . $portRel, $portContent);
        $createdFiles[] = $portRel;

        // 3. Infrastructure Driven SQLite Adapter
        $adapterRel = "src/Infrastructure/Persistence/Sqlite/Sqlite{$pascalName}Repository.php";
        $adapterContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\\{$pascalName};
use App\Domain\Port\Outbound\\{$pascalName}RepositoryPort;
use PDO;

/**
 * Driven SQLite Adapter generated by Tuzygent Hexagonal Harness
 */
final readonly class Sqlite{$pascalName}Repository implements {$pascalName}RepositoryPort
{
    public function __construct(
        private PDO \$pdo
    ) {
        \$this->pdo->exec('
            CREATE TABLE IF NOT EXISTS {$tableName} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                code TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL
            )
        ');
    }

    public function findAll(): array
    {
        \$stmt = \$this->pdo->query('SELECT * FROM {$tableName} ORDER BY id DESC');
        \$rows = \$stmt ? \$stmt->fetchAll() : [];
        return array_map(fn(array \$r) => new {$pascalName}(
            id: (int) \$r['id'],
            name: (string) \$r['name'],
            code: (string) \$r['code'],
            isActive: (bool) ((int) \$r['is_active']),
            createdAt: (string) \$r['created_at']
        ), \$rows);
    }

    public function findById(int \$id): ?{$pascalName}
    {
        \$stmt = \$this->pdo->prepare('SELECT * FROM {$tableName} WHERE id = ? LIMIT 1');
        \$stmt->execute([\$id]);
        \$r = \$stmt->fetch();
        if (!\$r) {
            return null;
        }
        return new {$pascalName}(
            id: (int) \$r['id'],
            name: (string) \$r['name'],
            code: (string) \$r['code'],
            isActive: (bool) ((int) \$r['is_active']),
            createdAt: (string) \$r['created_at']
        );
    }

    public function save({$pascalName} \$entity): int
    {
        \$now = date('Y-m-d H:i:s');
        if (\$entity->id === null) {
            \$stmt = \$this->pdo->prepare('INSERT INTO {$tableName} (name, code, is_active, created_at) VALUES (?, ?, ?, ?)');
            \$stmt->execute([\$entity->name, \$entity->code, \$entity->isActive ? 1 : 0, \$now]);
            return (int) \$this->pdo->lastInsertId();
        }
        \$stmt = \$this->pdo->prepare('UPDATE {$tableName} SET name = ?, code = ?, is_active = ? WHERE id = ?');
        \$stmt->execute([\$entity->name, \$entity->code, \$entity->isActive ? 1 : 0, \$entity->id]);
        return \$entity->id;
    }

    public function delete(int \$id): void
    {
        \$stmt = \$this->pdo->prepare('DELETE FROM {$tableName} WHERE id = ?');
        \$stmt->execute([\$id]);
    }
}

PHP;
        file_put_contents($this->basePath . '/' . $adapterRel, $adapterContent);
        $createdFiles[] = $adapterRel;

        // 4. Application Service (Use Case)
        $serviceRel = "src/Application/Service/{$pascalName}Service.php";
        $serviceContent = <<<PHP
<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\\{$pascalName};
use App\Domain\Port\Outbound\\{$pascalName}RepositoryPort;

/**
 * Inbound Use Case Service generated by Tuzygent Hexagonal Harness
 */
final readonly class {$pascalName}Service
{
    public function __construct(
        private {$pascalName}RepositoryPort \$repository
    ) {
    }

    /**
     * @return {$pascalName}[]
     */
    public function listAll(): array
    {
        return \$this->repository->findAll();
    }

    public function create(string \$name, string \$code): int
    {
        return \$this->repository->save(new {$pascalName}(
            id: null,
            name: trim(\$name),
            code: strtoupper(trim(\$code)),
            isActive: true
        ));
    }
}

PHP;
        file_put_contents($this->basePath . '/' . $serviceRel, $serviceContent);
        $createdFiles[] = $serviceRel;

        return $createdFiles;
    }

    private function buildAgentPrompt(HarnessTask $task, string $failureFeedback): string
    {
        $researchRel = '.harness/research/' . $task->taskCode . '.md';
        $feedbackSection = $failureFeedback !== ''
            ? "\n## PREVIOUS VERIFICATION GATE FAILURE (SELF-HEALING REQUIRED)\n```\n{$failureFeedback}\n```\n"
            : '';

        return <<<MD
# TUZYGENT AUTONOMOUS SELF-EVOLVING HARNESS SPECIFICATION
- **Task Code**: {$task->taskCode}
- **Title**: {$task->title}
- **Target Module/Entity**: {$task->targetName}
- **Specification**:
{$task->specification}

## ARCHITECTURAL CONSTRAINTS (HEXAGONAL PORTS & ADAPTERS)
1. Every PHP file MUST start with `declare(strict_types=1);`.
2. `src/Domain/` MUST remain 100% pure PHP (no `PDO`, no `\$_POST`/`\$_SESSION`, no `App\\Infrastructure` imports).
3. All database operations MUST go through an Outbound Port interface in `src/Domain/Port/Outbound/` and a Driven SQLite Adapter in `src/Infrastructure/Persistence/Sqlite/`.
4. All code changes MUST pass `./bin/tuzygent verify` (Syntax Lint + Hexagonal Purity Check + Integration Tests).
## EXECUTION PLAN
1. Inspect the current repository and identify the relevant existing features, conventions, and missing behavior.
2. For `research_and_develop`, research the requested topic with available web/search tools and reliable primary sources. Write a concise report to `{$researchRel}` before editing implementation code. Include the question, findings, source URLs, dates where available, and how each finding affects the feature. Never invent sources; state clearly when external research is unavailable.
3. Implement the requested, production-ready feature in this repository. Add or update focused regression coverage where appropriate, then run `./bin/tuzygent verify` and address failures caused by your changes.
4. Summarize changed files, decisions, verification results, and research limitations in your final response.
{$feedbackSection}
MD;
    }

    private function sanitizePascalCase(string $raw): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', ' ', $raw) ?? 'Feature';
        $studly = str_replace(' ', '', ucwords($clean));
        if ($studly === '' || is_numeric($studly[0])) {
            $studly = 'Feature' . $studly;
        }
        return $studly;
    }

    /**
     * @param string[] $relativeDirs
     * @return string[]
     */
    private function collectPhpFiles(array $relativeDirs): array
    {
        $files = [];
        foreach ($relativeDirs as $relDir) {
            $dir = $this->basePath . '/' . ltrim($relDir, '/');
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $files[] = $fileInfo->getRealPath();
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Collect all project source files that a coding agent can reasonably change,
     * including templates, stylesheets, scripts, and configuration alongside PHP.
     *
     * @param string[] $relativeDirs
     * @return string[]
     */
    private function collectSourceFiles(array $relativeDirs): array
    {
        $files = [];
        foreach ($relativeDirs as $relDir) {
            $dir = $this->basePath . '/' . ltrim($relDir, '/');
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $path = $fileInfo->getRealPath();
                    if ($path !== false) {
                        $files[] = $path;
                    }
                }
            }
        }
        sort($files);
        return $files;
    }

    private function isBinaryAvailable(string $binary): bool
    {
        $out = [];
        $code = 1;
        exec(sprintf('command -v %s >/dev/null 2>&1', escapeshellarg($binary)), $out, $code);
        return $code === 0;
    }
}
