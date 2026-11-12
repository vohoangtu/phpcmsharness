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
     * Liệt kê backend API cùng bộ Built-in Hexagonal Generator.
     *
     * @return array<string, array{name: string, binary: string, available: bool, description: string}>
     */
    public function discoverAgents(): array
    {
        return [
            'openai-compatible' => [
                'name' => 'OpenAI-compatible API', 'binary' => '',
                'available' => $this->getApiConfig()['configured'],
                'description' => 'Sinh đề xuất trong staging; chỉ cài lên website qua gói cập nhật đã kiểm định.',
            ],
            'builtin-hexagonal' => [
                'name' => 'Tuzygent Hexagonal Engine (Built-in)', 'binary' => 'php',
                'available' => true, 'description' => 'Sinh phân hệ Hexagonal tích hợp sẵn.',
            ],
        ];
    }

    /** @return array{base_url:string,api_key:string,model:string,configured:bool} */
    public function getApiConfig(): array
    {
        $path = $this->harnessDir . '/api_config.json';
        $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $stored = is_array($stored) ? $stored : [];
        $key = (string) ($stored['api_key'] ?? '');
        return [
            'base_url' => (string) ($stored['base_url'] ?? 'https://api.openai.com/v1'),
            'api_key' => '',
            'model' => (string) ($stored['model'] ?? ''),
            'configured' => $key !== '' && (string) ($stored['model'] ?? '') !== '',
        ];
    }

    /** Save API credentials locally; blank API key preserves the existing key. */
    public function saveApiConfig(array $data): void
    {
        $path = $this->harnessDir . '/api_config.json';
        $existing = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $existing = is_array($existing) ? $existing : [];
        $baseUrl = rtrim(trim((string) ($data['base_url'] ?? '')), '/');
        $model = trim((string) ($data['model'] ?? ''));
        $apiKey = trim((string) ($data['api_key'] ?? '')) ?: (string) ($existing['api_key'] ?? '');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Base URL phải là URL HTTP hoặc HTTPS hợp lệ.');
        }
        if ($model === '' || $apiKey === '') {
            throw new RuntimeException('Cần nhập Model và API key (hoặc giữ API key đã lưu).');
        }
        if (str_contains($apiKey, "\r") || str_contains($apiKey, "\n")) {
            throw new RuntimeException('API key không được chứa ký tự xuống dòng.');
        }
        $written = file_put_contents($path, json_encode(['base_url' => $baseUrl, 'model' => $model, 'api_key' => $apiKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Không thể ghi cấu hình API vào .harness.');
        }
        @chmod($path, 0600);
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

        $actionType = in_array(($data['action_type'] ?? ''), ['scaffold_module', 'agent_prompt', 'research_and_develop', 'self_audit'], true)
            ? (string) $data['action_type']
            : 'scaffold_module';

        $agentBackend = trim((string) ($data['agent_backend'] ?? 'openai-compatible'));
        if (!in_array($agentBackend, ['builtin-hexagonal', 'openai-compatible'], true)) {
            $agentBackend = 'openai-compatible';
        }

        $targetName = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($data['target_name'] ?? ''));
        if ($actionType === 'scaffold_module' && $targetName === '') {
            $targetName = 'Feature' . $nextNum;
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $actionType === 'scaffold_module'
                ? "Tự động phát triển phân hệ Hexagonal: {$targetName}"
                : 'Tác vụ tự phát triển mới';
        }

        $task = new HarnessTask(
            id: null,
            // The final code is assigned after SQLite returns the durable task ID.
            taskCode: 'TZY-PENDING',
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

        $taskId = $this->harnessRepository->save($task);
        $identifiedTask = new HarnessTask(
            id: $taskId,
            taskCode: sprintf('TZY-%03d', $taskId),
            title: $task->title,
            specification: $task->specification,
            actionType: $task->actionType,
            targetName: $task->targetName,
            agentBackend: $task->agentBackend,
            status: $task->status,
            attempts: $task->attempts,
            maxAttempts: $task->maxAttempts,
            snapshotId: $task->snapshotId,
            filesChanged: $task->filesChanged,
            verificationOutput: $task->verificationOutput,
            executionLog: $task->executionLog,
            createdAt: $task->createdAt,
            updatedAt: $task->updatedAt
        );
        $this->harnessRepository->save($identifiedTask);

        return $taskId;
    }

    public function deleteTask(int $id): void
    {
        $this->removeGeneratedDirectory($this->updatePackageDirectory($id), $this->harnessDir . '/updates/');
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
    public function runVerificationGate(bool $inStaging = false): array
    {
        if (!$inStaging) {
            $lock = fopen($this->harnessDir . '/update-install.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_SH | LOCK_NB)) {
                if (is_resource($lock)) fclose($lock);
                throw new RuntimeException('Không thể kiểm định khi task hoặc bản cập nhật đang chạy.');
            }
            $stagePath = '';
            try {
                $stagePath = $this->createStagingWorkspace(0);
                return (new self($this->harnessRepository, $stagePath))->runVerificationGate(true);
            } finally {
                if ($stagePath !== '') $this->removeStagingWorkspace($stagePath);
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
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
        $phpBin = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        $testFile = $this->basePath . '/tests/hexagon_test.php';
        $testOut = [];
        $testCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($testFile)), $testOut, $testCode);
        $workerTestOut = [];
        $workerTestCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($this->basePath . '/tests/worker_queue_test.php')), $workerTestOut, $workerTestCode);
        $testOut = array_merge($testOut, $workerTestOut);
        if ($workerTestCode !== 0) $testCode = $workerTestCode;
        $snapshotTestOut = [];
        $snapshotTestCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($this->basePath . '/tests/harness_snapshot_test.php')), $snapshotTestOut, $snapshotTestCode);
        $testOut = array_merge($testOut, $snapshotTestOut);
        if ($snapshotTestCode !== 0) $testCode = $snapshotTestCode;
        $updateTestOut = [];
        $updateTestCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($this->basePath . '/tests/harness_update_test.php')), $updateTestOut, $updateTestCode);
        $testOut = array_merge($testOut, $updateTestOut);
        if ($updateTestCode !== 0) $testCode = $updateTestCode;
        $concurrencyTestOut = [];
        $concurrencyTestCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($this->basePath . '/tests/worker_concurrency_test.php')), $concurrencyTestOut, $concurrencyTestCode);
        $testOut = array_merge($testOut, $concurrencyTestOut);
        if ($concurrencyTestCode !== 0) $testCode = $concurrencyTestCode;
        $orderTestOut = [];
        $orderTestCode = 0;
        exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($this->basePath . '/tests/order_operations_test.php')), $orderTestOut, $orderTestCode);
        $testOut = array_merge($testOut, $orderTestOut);
        if ($orderTestCode !== 0) $testCode = $orderTestCode;
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
     * Generate an update in an isolated workspace, verify it, and queue it
     * for installation through the website updater.
     */
    public function executeTask(int $taskId): HarnessTask
    {
        $lock = fopen($this->harnessDir . '/update-install.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Một bản cập nhật website đang được cài đặt; task sẽ được Workers thử lại sau.');
        }
        try {
            return $this->executeTaskInStaging($taskId);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function executeTaskInStaging(int $taskId): HarnessTask
    {
        $task = $this->harnessRepository->findById($taskId);
        if ($task === null) {
            throw new RuntimeException("Không tìm thấy Task #{$taskId}");
        }

        $logs = [];
        $logs[] = sprintf('[%s] 🚀 Tạo gói cập nhật cô lập cho Task %s: %s', date('H:i:s'), $task->taskCode, $task->title);
        $this->persistTaskProgress($task, 0, '', [], '', $logs);

        $attempt = 0;
        $maxAttempts = max(1, $task->maxAttempts);
        $lastGateResult = null;
        $filesChanged = [];
        $previousFailureFeedback = '';
        $lastFailure = '';
        $terminalApiFailure = false;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $filesChanged = [];
            $logs[] = sprintf('[%s] 🔄 Lần thử #%d/%d trong staging [%s]...', date('H:i:s'), $attempt, $maxAttempts, $task->agentBackend);
            $this->persistTaskProgress($task, $attempt, '', $filesChanged, '', $logs);

            $stagePath = '';
            try {
                $stagePath = $this->createStagingWorkspace((int) $task->id);
                $stagedHarness = new self($this->harnessRepository, $stagePath);
                $stageBefore = $stagedHarness->captureManagedFileHashes();
            } catch (\Throwable $e) {
                if ($stagePath !== '') $this->removeStagingWorkspace($stagePath);
                $lastFailure = 'Không thể tạo staging: ' . $e->getMessage();
                $logs[] = sprintf('[%s] ❌ %s', date('H:i:s'), $lastFailure);
                break;
            }

            $onProgress = function (array $currentFiles, string $summary) use ($task, &$attempt, &$filesChanged, &$logs): void {
                $filesChanged = array_values(array_unique(array_merge($filesChanged, $currentFiles)));
                $logs[] = sprintf('[%s] 🔧 %s', date('H:i:s'), $summary);
                $this->persistTaskProgress($task, $attempt, '', $filesChanged, '', $logs);
            };

            $proposalSucceeded = false;
            try {
                if ($task->agentBackend === 'openai-compatible') $this->copyApiConfigToStage($stagePath);
                $stagedHarness->runAgentProposalStep($task, $previousFailureFeedback, $logs, $onProgress);
                $filesChanged = $stagedHarness->findChangedManagedFiles($stageBefore);
                $proposalSucceeded = true;
            } catch (\Throwable $e) {
                $logs[] = sprintf('[%s] ⚠️ Lỗi trong bước sinh code: %s', date('H:i:s'), $e->getMessage());
                $lastFailure = 'Tạo đề xuất cập nhật thất bại: ' . $e->getMessage();
                $previousFailureFeedback = $lastFailure;
                $terminalApiFailure = preg_match('/HTTP 5\d\d|timeout|timed out/i', $e->getMessage()) === 1;
            }

            if ($proposalSucceeded && $task->actionType !== 'self_audit' && $filesChanged === []) {
                $proposalSucceeded = false;
                $lastFailure = 'Agent không tạo thay đổi nào để đóng gói.';
                $previousFailureFeedback = $lastFailure;
                $logs[] = sprintf('[%s] ⚠️ %s', date('H:i:s'), $lastFailure);
            }

            if (!$proposalSucceeded) {
                $this->persistTaskProgress($task, $attempt, '', $filesChanged, $lastFailure, $logs);
                $this->removeStagingWorkspace($stagePath);
                if ($terminalApiFailure) {
                    $logs[] = sprintf('[%s] ⏹️ Dừng retry vì API timeout/lỗi upstream; website không bị thay đổi.', date('H:i:s'));
                    break;
                }
                continue;
            }

            $logs[] = sprintf('[%s] 🧪 Chạy 3-Stage Gate trên staging; website đang chạy chưa bị thay đổi.', date('H:i:s'));
            try {
                $lastGateResult = $stagedHarness->runVerificationGate(true);
            } catch (\Throwable $e) {
                $lastFailure = 'Không thể chạy Gate trên staging: ' . $e->getMessage();
                $logs[] = sprintf('[%s] ❌ %s', date('H:i:s'), $lastFailure);
                $this->removeStagingWorkspace($stagePath);
                continue;
            }
            $logs[] = sprintf('[%s] %s Verification Gate: %s', date('H:i:s'), $lastGateResult['passed'] ? '✅' : '❌', $lastGateResult['passed'] ? '3/3 cổng đạt.' : 'Có cổng chưa đạt.');
            $this->persistTaskProgress($task, $attempt, '', $filesChanged, $lastGateResult['summary'], $logs);

            if ($lastGateResult['passed']) {
                if ($task->actionType === 'self_audit') {
                    $completedTask = new HarnessTask(
                        id: $task->id, taskCode: $task->taskCode, title: $task->title,
                        specification: $task->specification, actionType: $task->actionType,
                        targetName: $task->targetName, agentBackend: $task->agentBackend,
                        status: HarnessTaskStatus::COMPLETED, attempts: $attempt,
                        maxAttempts: $maxAttempts, snapshotId: '', filesChanged: [],
                        verificationOutput: $lastGateResult['summary'], executionLog: implode("\n", $logs)
                    );
                    $this->harnessRepository->save($completedTask);
                    $this->removeStagingWorkspace($stagePath);
                    return $this->harnessRepository->findById((int) $task->id) ?? $completedTask;
                }

                try {
                    $filesChanged = $this->createUpdatePackage((int) $task->id, $task->taskCode, $stagePath, $filesChanged, $stageBefore);
                    $this->copyResearchNoteFromStage((int) $task->id, $task->taskCode, $stagePath);
                } catch (\Throwable $e) {
                    $lastFailure = 'Không thể đóng gói bản cập nhật: ' . $e->getMessage();
                    $previousFailureFeedback = $lastFailure;
                    $logs[] = sprintf('[%s] ❌ %s', date('H:i:s'), $lastFailure);
                    $this->removeStagingWorkspace($stagePath);
                    continue;
                }
                $logs[] = sprintf('[%s] 📦 Gói cập nhật đã sẵn sàng. Cài đặt từ Dashboard để đưa lên website.', date('H:i:s'));
                $readyTask = new HarnessTask(
                    id: $task->id, taskCode: $task->taskCode, title: $task->title,
                    specification: $task->specification, actionType: $task->actionType,
                    targetName: $task->targetName, agentBackend: $task->agentBackend,
                    status: HarnessTaskStatus::READY_TO_INSTALL, attempts: $attempt,
                    maxAttempts: $maxAttempts, snapshotId: '', filesChanged: $filesChanged,
                    verificationOutput: $lastGateResult['summary'], executionLog: implode("\n", $logs)
                );
                $this->harnessRepository->save($readyTask);
                $this->removeStagingWorkspace($stagePath);
                return $this->harnessRepository->findById((int) $task->id) ?? $readyTask;
            }

            $lastFailure = "Verification Gate thất bại trên staging:\n" . $lastGateResult['summary'];
            $previousFailureFeedback = $lastGateResult['summary'];
            $logs[] = sprintf('[%s] ❌ Staging Gate thất bại ở lần thử #%d. Website vẫn giữ nguyên; sẽ thử đề xuất mới.', date('H:i:s'), $attempt);
            $this->removeStagingWorkspace($stagePath);
        }

        $logs[] = sprintf('[%s] ⛔ Không tạo được gói cập nhật sau %d lần thử. Mã nguồn website không bị chỉnh sửa.', date('H:i:s'), $attempt);

        $failedTask = new HarnessTask(
            id: $task->id, taskCode: $task->taskCode, title: $task->title,
            specification: $task->specification, actionType: $task->actionType,
            targetName: $task->targetName, agentBackend: $task->agentBackend,
            status: HarnessTaskStatus::FAILED, attempts: $attempt,
            maxAttempts: $maxAttempts, snapshotId: '', filesChanged: $filesChanged,
            verificationOutput: $lastFailure !== '' ? $lastFailure : ($lastGateResult['summary'] ?? 'Không tạo được gói cập nhật.'),
            executionLog: implode("\n", $logs)
        );
        $this->harnessRepository->save($failedTask);

        return $this->harnessRepository->findById((int) $task->id) ?? $failedTask;
    }

    /** Install a verified task package from the admin dashboard. */
    public function installUpdate(int $taskId): HarnessTask
    {
        $task = $this->harnessRepository->findById($taskId);
        if ($task === null || $task->status !== HarnessTaskStatus::READY_TO_INSTALL) {
            throw new RuntimeException('Task chưa có gói cập nhật đã vượt qua kiểm định để cài đặt.');
        }

        $packageDir = $this->updatePackageDirectory($taskId);
        $manifestPath = $packageDir . '/manifest.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (!is_array($manifest) || (int) ($manifest['schema_version'] ?? 0) !== 1
            || !is_array($manifest['files'] ?? null) || !is_array($manifest['deleted'] ?? null)
            || !is_array($manifest['base_files'] ?? null)
            || ((array) ($manifest['files'] ?? []) === [] && (array) ($manifest['deleted'] ?? []) === [])
            || (int) ($manifest['task_id'] ?? 0) !== $taskId
            || (string) ($manifest['task_code'] ?? '') !== $task->taskCode) {
            throw new RuntimeException('Không tìm thấy manifest hợp lệ cho gói cập nhật này.');
        }
        $lockPath = $this->harnessDir . '/update-install.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Một bản cập nhật khác đang được cài đặt.');
        }

        $snapshotId = '';
        $maintenanceStarted = false;
        $logs = preg_split('/\R/', $task->executionLog) ?: [];
        try {
            foreach (array_unique(array_merge(array_keys($manifest['files']), array_values($manifest['deleted']))) as $relativePath) {
                $relativePath = (string) $relativePath;
                $this->assertInstallablePath($relativePath);
                if (!array_key_exists($relativePath, $manifest['base_files'])) {
                    throw new RuntimeException("Manifest thiếu checksum source ban đầu: {$relativePath}");
                }
                $livePath = $this->basePath . '/' . $relativePath;
                $liveHash = is_file($livePath) ? (hash_file('sha256', $livePath) ?: '') : null;
                $expectedBaseHash = $manifest['base_files'][$relativePath];
                if ($liveHash !== $expectedBaseHash) {
                    throw new RuntimeException("Gói đã cũ vì website đã thay đổi {$relativePath}. Tạo lại gói trên source hiện tại trước khi cài.");
                }
            }

            $snapshotId = $this->createSnapshot('update_' . $task->taskCode);
            $maintenancePath = $this->basePath . '/storage/update-maintenance.json';
            $maintenanceTemp = $maintenancePath . '.tmp-' . bin2hex(random_bytes(5));
            $maintenanceState = json_encode(['task_id' => $taskId, 'snapshot_id' => $snapshotId, 'started_at' => time()], JSON_THROW_ON_ERROR);
            if (file_put_contents($maintenanceTemp, $maintenanceState, LOCK_EX) === false || !rename($maintenanceTemp, $maintenancePath)) {
                @unlink($maintenanceTemp);
                throw new RuntimeException('Không thể bật chế độ bảo trì trước khi cài đặt.');
            }
            $maintenanceStarted = true;
            $this->backupDatabaseForSnapshot($snapshotId);
            $logs[] = sprintf('[%s] 📦 Bắt đầu cài gói cập nhật từ Dashboard.', date('H:i:s'));
            $logs[] = sprintf('[%s] 📸 Sao lưu trước cập nhật: %s', date('H:i:s'), $snapshotId);

            foreach ((array) ($manifest['files'] ?? []) as $relativePath => $expectedHash) {
                $relativePath = (string) $relativePath;
                $this->assertInstallablePath($relativePath);
                $this->assertNoSymlinkComponents($relativePath);
                $source = $packageDir . '/files/' . $relativePath;
                if (!is_file($source) || !hash_equals((string) $expectedHash, hash_file('sha256', $source) ?: '')) {
                    throw new RuntimeException("Tệp gói cập nhật không hợp lệ: {$relativePath}");
                }
                $destination = $this->basePath . '/' . $relativePath;
                if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
                    throw new RuntimeException("Không thể tạo thư mục cập nhật cho {$relativePath}.");
                }
                $temporary = $destination . '.update-' . bin2hex(random_bytes(5));
                if (!copy($source, $temporary) || !rename($temporary, $destination)) {
                    @unlink($temporary);
                    throw new RuntimeException("Không thể kích hoạt tệp cập nhật {$relativePath}.");
                }
            }

            foreach ((array) ($manifest['deleted'] ?? []) as $relativePath) {
                $relativePath = (string) $relativePath;
                $this->assertInstallablePath($relativePath);
                $this->assertNoSymlinkComponents($relativePath);
                $destination = $this->basePath . '/' . $relativePath;
                if (is_file($destination) && !unlink($destination)) {
                    throw new RuntimeException("Không thể gỡ tệp cũ {$relativePath}.");
                }
            }

            $report = $this->verifyInstalledPackage($manifest);
            if (!$report['passed']) {
                $restored = $this->rollbackToSnapshot($snapshotId);
                $logs[] = sprintf('[%s] ❌ Health check sau cài đặt thất bại; %s.', date('H:i:s'), $restored ? 'đã khôi phục source và database' : 'khôi phục snapshot chưa hoàn tất');
                $status = $restored ? HarnessTaskStatus::ROLLED_BACK : HarnessTaskStatus::FAILED;
                $verificationOutput = "Post-install health check:\n" . $report['summary'] . "\n\nStaging Gate:\n" . $task->verificationOutput;
            } else {
                $logs[] = sprintf('[%s] ✅ Cài đặt website và integrity health check thành công.', date('H:i:s'));
                $status = HarnessTaskStatus::COMPLETED;
                $verificationOutput = "Post-install health check:\n" . $report['summary'] . "\n\nStaging Gate:\n" . $task->verificationOutput;
            }
        } catch (\Throwable $e) {
            $restored = $snapshotId !== '' && $this->rollbackToSnapshot($snapshotId);
            $logs[] = sprintf('[%s] ❌ Cài đặt thất bại: %s', date('H:i:s'), $e->getMessage());
            if ($restored) $logs[] = sprintf('[%s] ⏪ Đã khôi phục snapshot [%s].', date('H:i:s'), $snapshotId);
            elseif ($snapshotId !== '') $logs[] = sprintf('[%s] ⚠️ Không xác nhận được khôi phục hoàn chỉnh; cần kiểm tra website.', date('H:i:s'));
            else $logs[] = sprintf('[%s] ℹ️ Chưa thay đổi website vì lỗi phát sinh trước khi cài.', date('H:i:s'));
            $status = $restored ? HarnessTaskStatus::ROLLED_BACK : HarnessTaskStatus::FAILED;
            $verificationOutput = $e->getMessage();
        } finally {
            if ($maintenanceStarted && ($status ?? HarnessTaskStatus::FAILED) !== HarnessTaskStatus::FAILED) @unlink($this->basePath . '/storage/update-maintenance.json');
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $updatedTask = new HarnessTask(
            id: $task->id, taskCode: $task->taskCode, title: $task->title,
            specification: $task->specification, actionType: $task->actionType,
            targetName: $task->targetName, agentBackend: $task->agentBackend,
            status: $status, attempts: $task->attempts, maxAttempts: $task->maxAttempts,
            snapshotId: $snapshotId, filesChanged: $task->filesChanged,
            verificationOutput: $verificationOutput, executionLog: implode("\n", $logs),
            createdAt: $task->createdAt, updatedAt: date('Y-m-d H:i:s')
        );
        $this->harnessRepository->save($updatedTask);
        return $this->harnessRepository->findById($taskId) ?? $updatedTask;
    }

    private function createStagingWorkspace(int $taskId): string
    {
        $root = $this->harnessDir . '/staging';
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('Không thể tạo vùng staging cho Harness.');
        }
        $stage = $root . '/task-' . $taskId . '-' . bin2hex(random_bytes(5));
        if (!mkdir($stage, 0770, true)) throw new RuntimeException('Không thể khởi tạo workspace staging.');
        try {
            foreach (['config', 'src', 'public', 'tests', 'vendor'] as $directory) {
                $source = $this->basePath . '/' . $directory;
                if (is_dir($source)) $this->copyDirectory($source, $stage . '/' . $directory);
            }
            foreach (['storage', '.harness'] as $directory) {
                if (!is_dir($stage . '/' . $directory)) mkdir($stage . '/' . $directory, 0770, true);
            }
            $stageConfig = require $this->basePath . '/config/app.php';
            if (!is_array($stageConfig)) throw new RuntimeException('Cấu hình staging không hợp lệ.');
            $stageConfig['base_path'] = $stage;
            $stageConfig['storage_path'] = $stage . '/storage';
            $stageConfig['uploads_path'] = $stage . '/public/uploads';
            $stageConfig['db'] = ['driver' => 'sqlite', 'sqlite_path' => $stage . '/storage/database.sqlite'];
            if (file_put_contents($stage . '/config/app.php', "<?php\nreturn " . var_export($stageConfig, true) . ";\n", LOCK_EX) === false) throw new RuntimeException('Không thể cô lập cấu hình database staging.');
        } catch (\Throwable $e) {
            $this->removeStagingWorkspace($stage);
            throw $e;
        }
        return $stage;
    }

    private function copyApiConfigToStage(string $stagePath): void
    {
        $source = $this->harnessDir . '/api_config.json';
        if (!is_file($source)) return;
        $destination = $stagePath . '/.harness/api_config.json';
        if (!copy($source, $destination)) throw new RuntimeException('Không thể cấp cấu hình API tạm cho staging.');
        @chmod($destination, 0600);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new RuntimeException("Không thể tạo thư mục staging {$destination}.");
        }
        $iterator = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) continue;
            if ($entry->getPathname() === $this->basePath . '/public/uploads') continue;
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isDir()) $this->copyDirectory($entry->getPathname(), $target);
            elseif ($entry->isFile() && !copy($entry->getPathname(), $target)) {
                throw new RuntimeException('Không thể sao chép tệp vào staging.');
            }
        }
    }

    private function removeStagingWorkspace(string $stagePath): void
    {
        $root = $this->harnessDir . '/staging/';
        if (!str_starts_with($stagePath, $root) || !is_dir($stagePath)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname());
            else @unlink($entry->getPathname());
        }
        @rmdir($stagePath);
    }

    /** @param string[] $changedFiles @param array<string, string> $stageBefore @return string[] */
    private function createUpdatePackage(int $taskId, string $taskCode, string $stagePath, array $changedFiles, array $stageBefore): array
    {
        $packageDir = $this->updatePackageDirectory($taskId);
        if (is_dir($packageDir)) $this->removeGeneratedDirectory($packageDir, $this->harnessDir . '/updates/');
        if (!mkdir($packageDir, 0770, true) && !is_dir($packageDir)) throw new RuntimeException('Không thể tạo thư mục gói cập nhật.');
        $files = [];
        $deleted = [];
        $baseFiles = [];
        foreach ($changedFiles as $changedFile) {
            if (str_starts_with($changedFile, '.harness/research/')) continue;
            $isDeleted = str_ends_with($changedFile, ' (deleted)');
            $relativePath = $isDeleted ? substr($changedFile, 0, -10) : $changedFile;
            $this->assertInstallablePath($relativePath);
            $baseFiles[$relativePath] = $stageBefore[$relativePath] ?? null;
            if ($isDeleted) {
                $deleted[] = $relativePath;
                continue;
            }
            $source = $stagePath . '/' . $relativePath;
            if (!is_file($source)) throw new RuntimeException("Thiếu tệp staging để đóng gói: {$relativePath}");
            $destination = $packageDir . '/files/' . $relativePath;
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
                throw new RuntimeException('Không thể tạo cấu trúc tệp trong gói cập nhật.');
            }
            if (!copy($source, $destination)) throw new RuntimeException("Không thể đóng gói tệp {$relativePath}.");
            $files[$relativePath] = hash_file('sha256', $destination) ?: '';
        }
        if ($files === [] && $deleted === []) throw new RuntimeException('Gói cập nhật không chứa tệp để cài đặt.');
        $manifest = [
            'schema_version' => 1, 'task_id' => $taskId, 'task_code' => $taskCode,
            'created_at' => date(DATE_ATOM), 'files' => $files, 'deleted' => $deleted,
            'base_files' => $baseFiles,
        ];
        $written = file_put_contents($packageDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) throw new RuntimeException('Không thể ghi manifest gói cập nhật.');
        return array_merge(array_keys($files), array_map(static fn(string $path): string => $path . ' (deleted)', $deleted));
    }

    private function copyResearchNoteFromStage(int $taskId, string $taskCode, string $stagePath): void
    {
        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $taskCode) ?: ('task-' . $taskId);
        $source = $stagePath . '/.harness/research/' . $safeCode . '.md';
        if (!is_file($source)) return;
        $destinationDir = $this->harnessDir . '/research';
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0770, true) && !is_dir($destinationDir)) return;
        @copy($source, $destinationDir . '/' . $safeCode . '.md');
    }

    private function updatePackageDirectory(int $taskId): string
    {
        return $this->harnessDir . '/updates/task-' . $taskId;
    }

    /** @param array<string, mixed> $manifest
     *  @return array{passed: bool, summary: string}
     */
    private function verifyInstalledPackage(array $manifest): array
    {
        $errors = [];
        foreach ((array) ($manifest['files'] ?? []) as $relativePath => $expectedHash) {
            $relativePath = (string) $relativePath;
            $this->assertInstallablePath($relativePath);
            $path = $this->basePath . '/' . $relativePath;
            if (!is_file($path) || !hash_equals((string) $expectedHash, hash_file('sha256', $path) ?: '')) {
                $errors[] = "Tệp đã cài không khớp gói: {$relativePath}";
                continue;
            }
            if (str_ends_with(strtolower($relativePath), '.php')) {
                try {
                    token_get_all((string) file_get_contents($path), TOKEN_PARSE);
                } catch (\ParseError $e) {
                    $errors[] = sprintf('%s:%d %s', $relativePath, $e->getLine(), $e->getMessage());
                }
            }
        }
        foreach ((array) ($manifest['deleted'] ?? []) as $relativePath) {
            $relativePath = (string) $relativePath;
            $this->assertInstallablePath($relativePath);
            if (is_file($this->basePath . '/' . $relativePath)) $errors[] = "Tệp cũ vẫn còn sau cài đặt: {$relativePath}";
        }
        if ($errors === []) {
            $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
            $bootstrap = '$root = $argv[1]; require $root . "/vendor/autoload.php"; $config = require $root . "/config/app.php"; if (!is_array($config) || !class_exists("App\\Infrastructure\\Container\\Container") || !class_exists("App\\Presentation\\Http\\Router")) exit(1);';
            try {
                [$code, $output] = $this->runProcess([$php, '-r', $bootstrap, $this->basePath], 30);
                if ($code !== 0) $errors[] = 'Bootstrap health check thất bại: ' . trim($output);
            } catch (\Throwable $e) { $errors[] = 'Không thể kiểm tra bootstrap: ' . $e->getMessage(); }
        }
        return [
            'passed' => $errors === [],
            'summary' => $errors === []
                ? '✅ Cài đặt integrity check đạt: checksum, cú pháp PHP và bootstrap hợp lệ.'
                : implode("\n", $errors),
        ];
    }

    private function assertInstallablePath(string $relativePath): void
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if ($normalized !== $relativePath || str_starts_with($normalized, '/')
            || preg_match('~(^|/)\.\.(/|$)~', $normalized)
            || $normalized === 'config/app.php'
            || str_starts_with($normalized, 'public/uploads/')
            || preg_match('~^(config|src|public|tests)/.+~', $normalized) !== 1) {
            throw new RuntimeException("Đường dẫn ngoài phạm vi gói cập nhật: {$relativePath}");
        }
    }

    private function assertNoSymlinkComponents(string $relativePath): void
    {
        $cursor = $this->basePath;
        foreach (explode('/', $relativePath) as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor)) throw new RuntimeException("Gói cập nhật không được ghi qua symbolic link: {$relativePath}");
        }
    }

    private function removeGeneratedDirectory(string $directory, string $allowedRoot): void
    {
        if (!str_starts_with($directory, $allowedRoot) || !is_dir($directory)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname());
            else @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }

    /**
     * Persist a safe, user-facing progress snapshot so the admin dashboard can poll active work.
     * Raw agent output is deliberately excluded.
     *
     * @param string[] $filesChanged
     * @param string[] $logs
     */
    private function persistTaskProgress(
        HarnessTask $task,
        int $attempt,
        string $snapshotId,
        array $filesChanged,
        string $verificationOutput,
        array $logs
    ): void {
        $this->harnessRepository->save(new HarnessTask(
            id: $task->id,
            taskCode: $task->taskCode,
            title: $task->title,
            specification: $task->specification,
            actionType: $task->actionType,
            targetName: $task->targetName,
            agentBackend: $task->agentBackend,
            status: HarnessTaskStatus::RUNNING,
            attempts: $attempt,
            maxAttempts: $task->maxAttempts,
            snapshotId: $snapshotId,
            filesChanged: $filesChanged,
            verificationOutput: $verificationOutput,
            executionLog: implode("\n", $logs),
            createdAt: $task->createdAt,
            updatedAt: date('Y-m-d H:i:s')
        ));
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
            'checksums' => [],
        ];

        foreach ($files as $absPath) {
            $relPath = str_replace($this->basePath . '/', '', $absPath);
            $destPath = $targetDir . '/files/' . $relPath;
            if (!is_dir(dirname($destPath))) {
                mkdir(dirname($destPath), 0775, true);
            }
            if (!copy($absPath, $destPath)) throw new RuntimeException("Không thể sao lưu {$relPath}.");
            $manifest['files'][] = $relPath;
            $manifest['checksums'][$relPath] = hash_file('sha256', $destPath) ?: throw new RuntimeException("Không thể kiểm tra snapshot {$relPath}.");
        }

        if (file_put_contents($targetDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Không thể ghi manifest snapshot.');

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

        foreach ($manifest['files'] as $relPath) {
            if (!is_string($relPath) || str_contains($relPath, '\\') || preg_match('~^(config|src|public|tests)/.+~', $relPath) !== 1 || preg_match('~(^|/)\.\.(/|$)~', $relPath)) return false;
            if (str_starts_with($relPath, 'public/uploads/')) continue;
            $source = $snapDir . '/files/' . $relPath;
            if (!is_file($source)) return false;
            if (isset($manifest['checksums'][$relPath]) && !hash_equals((string) $manifest['checksums'][$relPath], hash_file('sha256', $source) ?: '')) return false;
        }

        $recordedFiles = array_flip($manifest['files']);

        // Snapshot trước file_scope chỉ ghi nhận PHP; giữ nguyên hành vi tương thích.
        $currentFiles = ($manifest['file_scope'] ?? '') === 'project-source-v1'
            ? $this->collectSourceFiles(['config', 'src', 'public', 'tests'])
            : $this->collectPhpFiles(['config', 'src', 'public', 'tests']);
        foreach ($currentFiles as $absPath) {
            $relPath = str_replace($this->basePath . '/', '', $absPath);
            if (!isset($recordedFiles[$relPath]) && !@unlink($absPath) && is_file($absPath)) {
                return false;
            }
        }

        // Khôi phục toàn bộ tệp trong Snapshot
        foreach ($manifest['files'] as $relPath) {
            if (str_starts_with($relPath, 'public/uploads/')) continue;
            $srcFile = $snapDir . '/files/' . $relPath;
            $destFile = $this->basePath . '/' . $relPath;
            if (!is_file($srcFile)) return false;
            if (!is_dir(dirname($destFile)) && !mkdir(dirname($destFile), 0775, true) && !is_dir(dirname($destFile))) {
                return false;
            }
            if (!copy($srcFile, $destFile)) return false;
        }

        return $this->restoreDatabaseBackup($safeId);
    }

    /** Recover an update process interrupted while the site was in maintenance mode. */
    public function recoverInterruptedUpdate(): bool
    {
        $maintenancePath = $this->basePath . '/storage/update-maintenance.json';
        if (!is_file($maintenancePath)) return true;

        $lock = fopen($this->harnessDir . '/update-install.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return false;
        }
        try {
            $state = json_decode((string) @file_get_contents($maintenancePath), true);
            $snapshotId = is_array($state) ? (string) ($state['snapshot_id'] ?? '') : '';
            if ($snapshotId === '' || !$this->rollbackToSnapshot($snapshotId)) return false;
            return unlink($maintenancePath) || !is_file($maintenancePath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function backupDatabaseForSnapshot(string $snapshotId): void
    {
        $appConfigPath = $this->basePath . '/config/app.php';
        $appConfig = is_file($appConfigPath) ? require $appConfigPath : [];
        $dbConfig = is_array($appConfig) ? (array) ($appConfig['db'] ?? []) : [];
        $driver = (string) ($dbConfig['driver'] ?? 'sqlite');
        if ($driver !== 'sqlite') {
            throw new RuntimeException('Website updater hiện yêu cầu SQLite để tạo và khôi phục bản sao lưu cơ sở dữ liệu an toàn.');
        }
        $databasePath = (string) ($dbConfig['sqlite_path'] ?? ($this->basePath . '/storage/database.sqlite'));
        if (!str_starts_with($databasePath, '/')) $databasePath = $this->basePath . '/' . $databasePath;
        if (!is_file($databasePath)) return;
        $backupPath = $this->snapshotsDir . '/' . basename($snapshotId) . '/database.sqlite';
        $pdo = new \PDO('sqlite:' . $databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA wal_checkpoint(FULL)');
        $quotedBackup = str_replace("'", "''", $backupPath);
        $pdo->exec("VACUUM INTO '{$quotedBackup}'");
        @chmod($backupPath, 0600);
    }

    private function restoreDatabaseBackup(string $snapshotId): bool
    {
        $backupPath = $this->snapshotsDir . '/' . basename($snapshotId) . '/database.sqlite';
        if (!is_file($backupPath)) return true;
        $appConfigPath = $this->basePath . '/config/app.php';
        $appConfig = is_file($appConfigPath) ? require $appConfigPath : [];
        $dbConfig = is_array($appConfig) ? (array) ($appConfig['db'] ?? []) : [];
        $databasePath = (string) ($dbConfig['sqlite_path'] ?? ($this->basePath . '/storage/database.sqlite'));
        if (!str_starts_with($databasePath, '/')) $databasePath = $this->basePath . '/' . $databasePath;
        $pdo = new \PDO('sqlite:' . $databasePath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($backupPath) . ' AS update_backup');
        try {
            $pdo->beginTransaction();
            $objects = $pdo->query("SELECT type, name FROM main.sqlite_master WHERE type IN ('view','trigger','index','table') AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'view' THEN 0 WHEN 'trigger' THEN 1 WHEN 'index' THEN 2 ELSE 3 END")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($objects as $object) {
                $name = '"' . str_replace('"', '""', (string) $object['name']) . '"';
                $pdo->exec('DROP ' . strtoupper((string) $object['type']) . ' IF EXISTS main.' . $name);
            }

            $schemas = $pdo->query("SELECT type, sql FROM update_backup.sqlite_master WHERE sql IS NOT NULL AND type IN ('table','index','trigger','view') AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 WHEN 'trigger' THEN 2 ELSE 3 END")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($schemas as $schema) $pdo->exec((string) $schema['sql']);

            $tables = $pdo->query("SELECT name FROM update_backup.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $quoted = '"' . str_replace('"', '""', (string) $table) . '"';
                $pdo->exec('INSERT INTO main.' . $quoted . ' SELECT * FROM update_backup.' . $quoted);
            }

            $hasMainSequence = (bool) $pdo->query("SELECT 1 FROM main.sqlite_master WHERE type='table' AND name='sqlite_sequence'")->fetchColumn();
            if ($hasMainSequence) $pdo->exec('DELETE FROM main.sqlite_sequence');
            $hasSequence = (bool) $pdo->query("SELECT 1 FROM update_backup.sqlite_master WHERE type='table' AND name='sqlite_sequence'")->fetchColumn();
            if ($hasSequence) {
                $sequences = $pdo->query('SELECT name, seq FROM update_backup.sqlite_sequence')->fetchAll(\PDO::FETCH_ASSOC);
                $insertSequence = $pdo->prepare('INSERT INTO main.sqlite_sequence (name, seq) VALUES (?, ?)');
                foreach ($sequences as $sequence) $insertSequence->execute([(string) $sequence['name'], (int) $sequence['seq']]);
            }
            $userVersion = (int) $pdo->query('PRAGMA update_backup.user_version')->fetchColumn();
            $applicationId = (int) $pdo->query('PRAGMA update_backup.application_id')->fetchColumn();
            $pdo->commit();
            $pdo->exec('PRAGMA user_version = ' . $userVersion);
            $pdo->exec('PRAGMA application_id = ' . $applicationId);
            return true;
        } catch (\Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return false;
        } finally {
            try { $pdo->exec('DETACH DATABASE update_backup'); } catch (\Throwable) {}
        }
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
     *     tasks_failed: int,
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
            'tasks_failed' => 0,
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
            'tasks_failed' => max(0, (int) ($raw['tasks_failed'] ?? 0)),
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
        $autoHeal = false;
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
            $phpBin = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
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
    public function runContinuousCycle(?bool $overrideAutoDiscover = null, bool $queueTaskOnly = false): array
    {
        $queueTaskOnly = true;
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
            if ($queueTaskOnly) {
                $summary = sprintf('Chu kỳ #%d: Đề xuất gửi %s vào hàng đợi Workers.', $cycle, $nextTask->taskCode);
                array_unshift($events, sprintf('[%s] 📥 %s', date('H:i:s'), $summary));
                $updatedState = $this->saveContinuousState([
                    'cycles_completed' => $cycle,
                    'last_heartbeat' => date('Y-m-d H:i:s'),
                    'last_cycle_summary' => $summary,
                    'recent_events' => array_slice($events, 0, 25),
                ]);
                return [
                    'cycle' => $cycle,
                    'action' => 'queue_task',
                    'task' => $nextTask,
                    'gate_passed' => null,
                    'summary' => $summary,
                    'state' => $updatedState,
                ];
            }
            $executed = $this->executeTask((int) $nextTask->id);
            $passed = in_array($executed->status, [HarnessTaskStatus::COMPLETED, HarnessTaskStatus::READY_TO_INSTALL], true);

            $tasksExecuted = $state['tasks_executed'] + 1;
            $tasksSucceeded = $state['tasks_succeeded'] + ($passed ? 1 : 0);
            $rolledBack = $executed->status === HarnessTaskStatus::ROLLED_BACK;
            $failed = $executed->status === HarnessTaskStatus::FAILED;
            $tasksRolledBack = $state['tasks_rolled_back'] + ($rolledBack ? 1 : 0);
            $tasksFailed = $state['tasks_failed'] + ($failed ? 1 : 0);
            $lastHealthySnap = $passed && $executed->snapshotId !== ''
                ? $executed->snapshotId
                : $state['last_healthy_snapshot'];

            $summary = $executed->status === HarnessTaskStatus::READY_TO_INSTALL
                ? sprintf('Chu kỳ #%d: Gói cập nhật %s đã vượt Gate và đang chờ cài đặt từ Dashboard.', $cycle, $executed->taskCode)
                : ($passed
                    ? sprintf('Chu kỳ #%d: Hoàn tất %s (%s) sau %d lần thử — PASS 3/3 Gates!', $cycle, $executed->taskCode, $executed->title, $executed->attempts)
                    : sprintf('Chu kỳ #%d: Task %s thất bại; website được giữ nguyên.', $cycle, $executed->taskCode));

            $eventIcon = match ($executed->status) {
                HarnessTaskStatus::READY_TO_INSTALL => '📦',
                HarnessTaskStatus::COMPLETED => '✅',
                HarnessTaskStatus::ROLLED_BACK => '⏪',
                default => '❌',
            };
            array_unshift($events, sprintf('[%s] %s %s', date('H:i:s'), $eventIcon, $summary));

            $updatedState = $this->saveContinuousState([
                'cycles_completed' => $cycle,
                'tasks_executed' => $tasksExecuted,
                'tasks_succeeded' => $tasksSucceeded,
                'tasks_rolled_back' => $tasksRolledBack,
                'tasks_failed' => $tasksFailed,
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
        try {
            $gateReport = $this->runVerificationGate();
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Không thể kiểm định khi task hoặc bản cập nhật đang chạy')) throw $e;
            $summary = sprintf('Chu kỳ #%d: Workers đang xử lý; sẽ kiểm định khi rảnh.', $cycle);
            $updatedState = $this->saveContinuousState(['cycles_completed' => $cycle, 'last_heartbeat' => date('Y-m-d H:i:s'), 'last_cycle_summary' => $summary]);
            return ['cycle' => $cycle, 'action' => 'busy', 'task' => null, 'gate_passed' => null, 'summary' => $summary, 'state' => $updatedState];
        }
        $gatePassed = $gateReport['passed'];

        if ($gatePassed) {
            $summary = sprintf('Chu kỳ #%d: Tuần tra Continuous Watch — Backlog trống, 3/3 Verification Gates PASS 100%%.', $cycle);
            array_unshift($events, sprintf('[%s] 🛡️ %s', date('H:i:s'), $summary));
        } else {
            $this->stopContinuousMode(false);
            $summary = sprintf('Chu kỳ #%d: Verification Gate thất bại; đã dừng Continuous và chờ quản trị viên kiểm tra.', $cycle);
            array_unshift($events, sprintf('[%s] ⚠️ %s', date('H:i:s'), $summary));
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

            $cycleResult = $this->runContinuousCycle($autoDiscover, true);
            $cyclesRun++;

            if ($onCycle !== null) {
                try { $onCycle($cycleResult); }
                catch (\Throwable $e) {
                    $this->stopContinuousMode(false);
                    $state = $this->getContinuousState();
                    $events = $state['recent_events'];
                    array_unshift($events, sprintf('[%s] ❌ Không thể gửi task vào Workers: %s', date('H:i:s'), $e->getMessage()));
                    $this->saveContinuousState(['last_cycle_summary' => 'Không thể gửi task vào Workers; Continuous đã dừng.', 'recent_events' => array_slice($events, 0, 25)]);
                    throw $e;
                }
            } elseif (($cycleResult['action'] ?? '') === 'queue_task') {
                $this->stopContinuousMode(false);
                throw new RuntimeException('Continuous cần Workers để gửi task vào hàng đợi.');
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
            if (in_array($task->status, [HarnessTaskStatus::BACKLOG, HarnessTaskStatus::RUNNING, HarnessTaskStatus::READY_TO_INSTALL], true)) {
                return null;
            }
        }

        // Chỉ tự khám phá đề tài bằng API khi đã cấu hình thông tin truy cập.
        $agentBackend = $this->availableBrainstormBackend();
        if ($agentBackend !== null) {
            $existingTargets = [];
            foreach ($this->harnessRepository->findAll() as $task) {
                // Re-scan completed work so a source signal can be discovered again
                // if it regresses. Keep active and failed targets suppressed to avoid
                // duplicate backlog items or endless retry loops in continuous mode.
                if ($task->targetName !== '' && $task->status !== HarnessTaskStatus::COMPLETED) {
                    $existingTargets[strtolower($task->targetName)] = true;
                }
            }

            // Nạp các đề tài dựa trên bằng chứng trong source trước khi hỏi agent mở rộng.
            $sourceTopics = (new CmsTopicGenerator($this->basePath))->generate($existingTargets);
            if ($sourceTopics !== []) {
                $firstTaskId = null;
                foreach ($sourceTopics as $topic) {
                    $taskId = $this->createTask([
                        'title' => $topic['title'],
                        'action_type' => 'research_and_develop',
                        'target_name' => $topic['target_name'],
                        'specification' => $topic['specification'],
                        'agent_backend' => $agentBackend,
                        'max_attempts' => 3,
                    ]);
                    $firstTaskId ??= $taskId;
                }

                return $firstTaskId;
            }

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
            if ($t->targetName !== '' && in_array($t->status, [HarnessTaskStatus::COMPLETED, HarnessTaskStatus::READY_TO_INSTALL], true)) {
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
        return $this->getApiConfig()['configured'] ? 'openai-compatible' : null;
    }

    /**
     * Bước đề xuất / sinh code mới qua API hoặc bộ sinh Hexagonal tích hợp.
     *
     * @param string[] $logs
     * @return string[] Danh sách các tệp đã tạo/cập nhật
     */
    private function runAgentProposalStep(HarnessTask $task, string $failureFeedback, array &$logs, ?callable $onProgress = null): array
    {
        if ($task->actionType === 'self_audit') {
            $logs[] = sprintf('[%s] 🔍 Thực hiện Self-Audit kiểm toán toàn bộ kiến trúc Hexagonal.', date('H:i:s'));
            return [];
        }

        if ($task->actionType === 'research_and_develop') {
            if ($task->agentBackend === 'builtin-hexagonal') {
                throw new RuntimeException('Tác vụ nghiên cứu cần chọn OpenAI-compatible API.');
            }
            return $this->runOpenAiCompatible($task, $failureFeedback, $logs, $onProgress);
        }

        if ($task->actionType === 'scaffold_module' || $task->agentBackend === 'builtin-hexagonal') {
            $entityName = $this->sanitizePascalCase($task->targetName !== '' ? $task->targetName : 'CustomFeature');
            $logs[] = sprintf('[%s] 🏗️ Built-in Hexagonal Generator đang khởi tạo phân hệ dọc (Vertical Slice) cho thực thể [%s]...', date('H:i:s'), $entityName);
            $generated = $this->scaffoldHexagonalVerticalSlice($entityName, $task->specification);
            $logs[] = sprintf('[%s] 📄 Đã sinh tự động %d tệp theo chuẩn Ports & Adapters.', date('H:i:s'), count($generated));
            return $generated;
        }

        return $this->runOpenAiCompatible($task, $failureFeedback, $logs, $onProgress);
    }

    /**
     * Gọi OpenAI-compatible API; unified diff chỉ được áp dụng vào workspace staging.
     *
     * @param string[] $logs
     * @return string[]
     */
    private function runOpenAiCompatible(HarnessTask $task, string $failureFeedback, array &$logs, ?callable $onProgress = null): array
    {
        $harnessDir = $this->basePath . '/.harness';
        if (!is_dir($harnessDir)) {
            mkdir($harnessDir, 0775, true);
        }
        $promptFile = $harnessDir . '/CURRENT_TASK_PROMPT.md';
        $promptContent = $this->buildAgentPrompt($task, $failureFeedback);
        file_put_contents($promptFile, $promptContent);
        $logs[] = sprintf('[%s] 📝 Đã xuất ngữ cảnh kiến trúc & đặc tả vào .harness/CURRENT_TASK_PROMPT.md', date('H:i:s'));

        $configPath = $this->harnessDir . '/api_config.json';
        $config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
        if (!is_array($config) || empty($config['api_key']) || empty($config['model']) || empty($config['base_url'])) {
            throw new RuntimeException('Hãy cấu hình Base URL, API key và Model trong Dashboard Harness trước khi chạy task.');
        }
        $prompt = "Thực hiện task lập trình dưới đây. Trả về unified diff nhỏ nhất có thể, không markdown fence, không giải thích. Chỉ sửa các tệp thật sự cần thiết; không tạo lại toàn bộ module nếu có thể chia thành lát cắt nhỏ. Diff phải dùng đường dẫn tương đối từ root, không sửa tệp ngoài config/, src/, public/, tests/ hoặc .harness/research/; tuyệt đối không thay đổi config/app.php vì đây là cấu hình riêng của website. Nếu không cần thay đổi, trả về NO_CHANGES.\n\n"
            . $promptContent . "\n\n## Relevant repository files (task-scoped, truncated)\n" . $this->buildApiSourceContext($task)
            . "\nYêu cầu bổ sung: chạy verify sẽ được Harness thực hiện sau khi áp dụng diff. Feedback lần trước: {$failureFeedback}\n";
        $logs[] = sprintf('[%s] 🌐 Gọi OpenAI-compatible API (%s).', date('H:i:s'), (string) $config['model']);
        $before = $this->captureManagedFileHashes();
        if ($onProgress !== null) {
            $onProgress([], 'Đang gửi yêu cầu tới OpenAI-compatible API.');
        }
        $payload = json_encode(['model' => $config['model'], 'messages' => [
            ['role' => 'system', 'content' => 'You are a careful software engineer. Return only a unified diff.'],
            ['role' => 'user', 'content' => $prompt],
        ], 'temperature' => 0.2], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 540, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$config['api_key']}\r\n",
            'content' => $payload,
        ]]);
        $response = @file_get_contents(rtrim((string) $config['base_url'], '/') . '/chat/completions', false, $context);
        if ($response === false) {
            $streamError = strtolower((string) (error_get_last()['message'] ?? ''));
            if (str_contains($streamError, 'timed out') || str_contains($streamError, 'timeout')) {
                throw new RuntimeException('OpenAI-compatible API timeout sau 540 giây; không có đề xuất nào được áp dụng.');
            }
            throw new RuntimeException('Không kết nối được OpenAI-compatible API. Kiểm tra Base URL và kết nối mạng máy chủ.');
        }
        $statusLine = is_array($http_response_header ?? null) ? ($http_response_header[0] ?? '') : '';
        if (preg_match('~\\s([45][0-9]{2})\\s~', $statusLine, $statusMatch) === 1) {
            throw new RuntimeException('OpenAI-compatible API trả HTTP ' . $statusMatch[1] . '. Kiểm tra Base URL, API key và model.');
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            throw new RuntimeException('API trả lỗi: ' . (string) ($decoded['error']['message'] ?? substr($response, 0, 1000)));
        }
        $output = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        $output = preg_replace('/^```(?:diff)?\s*|\s*```$/', '', $output) ?? $output;
        if ($output === 'NO_CHANGES') {
            throw new RuntimeException('API không đề xuất thay đổi nào.');
        }
        if (preg_match_all('/^\+\+\+ b\/(.+)$/m', $output, $matches) === false || empty($matches[1])) {
            throw new RuntimeException('API không trả về unified diff hợp lệ.');
        }
        foreach ($matches[1] as $relativePath) {
            $normalized = str_replace('\\', '/', $relativePath);
            if (str_starts_with($normalized, '/') || preg_match('~(^|/)\.\.(/|$)~', $normalized)
                || $normalized === 'config/app.php'
                || preg_match('~^(config|src|public|tests|\.harness/research)/~', $normalized) !== 1) {
                throw new RuntimeException("API đề xuất sửa tệp ngoài phạm vi cho phép: {$relativePath}");
            }
        }
        $patchFile = $this->harnessDir . '/CURRENT_TASK.patch';
        file_put_contents($patchFile, $output, LOCK_EX);
        if (($decoded['choices'][0]['finish_reason'] ?? '') === 'length') throw new RuntimeException('API trả patch bị cắt cụt; hãy chia nhỏ task.');
        [$exitCode, $applyOutput] = $this->runProcess(['git', 'apply', '--recount', '--whitespace=nowarn', '--', $patchFile], 60);
        if ($exitCode !== 0) {
            throw new RuntimeException('Không thể áp dụng unified diff do API: ' . trim($applyOutput));
        }

        if ($task->actionType === 'research_and_develop') {
            $researchDir = $this->harnessDir . '/research';
            if (!is_dir($researchDir)) {
                mkdir($researchDir, 0775, true);
            }
            $researchRel = '.harness/research/' . $task->taskCode . '.md';
            $researchPath = $this->basePath . '/' . $researchRel;
            if (!is_file($researchPath)) {
                file_put_contents($researchPath, "# Research: {$task->title}\n\n{$task->specification}\n");
            }
        }

        $changed = $this->findChangedManagedFiles($before);
        $implementationChanges = array_values(array_filter(
            $changed,
            static fn(string $path): bool => preg_match('~^(config|src|public|tests)/~', $path) === 1
        ));
        if (empty($implementationChanges)) {
            throw new RuntimeException('Agent kết thúc thành công nhưng không tạo hoặc sửa tệp nào trong phạm vi dự án.');
        }
        $logs[] = sprintf('[%s] 📄 API đã đề xuất thay đổi %d tệp.', date('H:i:s'), count($changed));
        return $changed;
    }

    private function buildApiSourceContext(HarnessTask $task): string
    {
        $terms = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($task->title . ' ' . $task->targetName . ' ' . $task->specification)) ?: [];
        $terms = array_values(array_unique(array_filter($terms, static fn(string $term): bool => mb_strlen($term) >= 4)));
        $candidates = [];
        foreach (['config', 'src', 'public'] as $dir) {
            $absolute = $this->basePath . '/' . $dir;
            if (!is_dir($absolute)) continue;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(php|sql|md|json)$/i', $file->getFilename())) {
                    $path = $file->getPathname();
                    $relative = substr($path, strlen($this->basePath) + 1);
                    $pathText = mb_strtolower($relative);
                    $content = @file_get_contents($path);
                    if ($content === false) continue;
                    $contentText = mb_strtolower($content);
                    $score = 0;
                    foreach ($terms as $term) {
                        if (str_contains($pathText, $term)) $score += 8;
                        $score += min(4, substr_count($contentText, $term));
                    }
                    $candidates[] = ['path' => $path, 'relative' => $relative, 'content' => $content, 'score' => $score];
                }
            }
        }
        usort($candidates, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['relative'], $b['relative']));
        $context = '';
        foreach (array_slice($candidates, 0, 24) as $candidate) {
            $content = (string) $candidate['content'];
            $remaining = 42000 - strlen($context);
            if ($remaining <= 0) break;
            if (strlen($content) > $remaining) $content = substr($content, 0, $remaining) . "\n… [file truncated]";
            $context .= "\n### {$candidate['relative']}\n```\n{$content}\n```\n";
        }
        return $context;
    }

    /** @return array{0: int, 1: string} */
    private function runProcess(array $command, int $timeoutSeconds, ?callable $onProgress = null): array
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->basePath);
        if (!is_resource($process)) {
            throw new RuntimeException('Không thể khởi chạy tiến trình xác minh unified diff.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $startedAt = microtime(true);
        $lastProgressAt = $startedAt;
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
            if ($onProgress !== null && microtime(true) - $lastProgressAt >= 5) {
                try {
                    $onProgress();
                } catch (\Throwable) {
                    // Progress reporting must not interrupt the agent process.
                }
                $lastProgressAt = microtime(true);
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
            throw new RuntimeException("AI Agent vượt quá giới hạn chạy {$timeoutSeconds} giây.");
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

    /** @param array<string, string> $before
     *  @return string[]
     */
    private function findChangedManagedFiles(array $before): array
    {
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
        return $changed;
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
2. For `research_and_develop`, write a concise report to `{$researchRel}` as part of the returned diff. The API has no browsing tools: do not claim live research or invent source URLs; state clearly when external research is unavailable.
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
                    if ($path !== false && str_starts_with($path, $this->basePath . '/public/uploads/')) continue;
                    if ($path !== false) {
                        $files[] = $path;
                    }
                }
            }
        }
        sort($files);
        return $files;
    }

}
