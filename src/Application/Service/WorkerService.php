<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\WorkerJob;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\ModuleRegistryPort;
use App\Domain\Port\Outbound\WorkerJobQueuePort;
use RuntimeException;

final readonly class WorkerService
{
    public function __construct(
        private WorkerJobQueuePort $queue,
        private ModuleRegistryPort $modules,
        private string $basePath,
        private TuzygentHarnessService $harness,
    ) {}

    /** @param array<string, mixed> $payload */
    public function enqueue(string $type, array $payload): int
    {
        if (!$this->modules->isEnabled(ModuleKey::WORKERS)) throw new RuntimeException('Workers đang tắt; không thể nhận job mới.');
        if ($type !== 'harness_task' || (int)($payload['task_id'] ?? 0) < 1) throw new RuntimeException('Loại job hoặc payload không hợp lệ.');
        $id = $this->queue->enqueue($type, $payload);
        $this->startWorker();
        return $id;
    }

    /** @return WorkerJob[] */
    public function listJobs(): array { return $this->queue->list(); }
    public function hasActiveHarnessTask(int $taskId): bool
    {
        foreach ($this->queue->list(500) as $job) {
            if ($job->type === 'harness_task' && (int)($job->payload['task_id'] ?? 0) === $taskId && in_array($job->status, ['queued', 'running'], true)) return true;
        }
        return false;
    }
    public function retry(int $id): bool { $ok = $this->queue->retry($id); if ($ok) $this->startWorker(); return $ok; }
    public function cancel(int $id): bool { return $this->queue->cancel($id); }

    public function run(string $token): int
    {
        $expected = is_file($this->tokenPath()) ? trim((string)file_get_contents($this->tokenPath())) : '';
        if ($expected === '' || !hash_equals($expected, $token)) return 3;
        $this->queue->recoverRunning();
        $idleChecks = 0;
        while (true) {
            $job = $this->queue->claimNext();
            if ($job === null) {
                // Keep the lock briefly so enqueue/launch races cannot strand a just-created job.
                if (++$idleChecks >= 20) return 0;
                usleep(100000);
                continue;
            }
            $idleChecks = 0;
            try {
                if ($job->type !== 'harness_task') throw new RuntimeException('Không có handler cho loại job này.');
                $task = $this->harness->executeTask((int)($job->payload['task_id'] ?? 0));
                $result = json_encode(['task_id'=>$task->id,'task_code'=>$task->taskCode,'status'=>$task->status->value,'attempts'=>$task->attempts,'snapshot_id'=>$task->snapshotId,'files_changed'=>$task->filesChanged,'verification_output'=>$task->verificationOutput], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                // Business rollback is a completed worker execution; its result describes the Harness failure.
                $this->queue->finish((int)$job->id, 'completed', $result, null);
            } catch (\Throwable $e) {
                $this->queue->finish((int)$job->id, 'failed', null, $e->getMessage());
            }
        }
    }

    /** Start a draining worker after Workers is disabled, so already accepted jobs still finish. */
    public function wakeWorker(): void { $this->startWorker(); }

    private function startWorker(): void
    {
        $path = $this->basePath . '/storage/workers';
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) throw new RuntimeException('Không thể tạo thư mục Workers.');
        $tokenPath = $this->tokenPath();
        if (!is_file($tokenPath)) {
            $tokenHandle = @fopen($tokenPath, 'x');
            if ($tokenHandle !== false) {
                fwrite($tokenHandle, bin2hex(random_bytes(32)));
                fclose($tokenHandle);
                @chmod($tokenPath, 0600);
            }
        }
        $php = escapeshellarg(PHP_BINARY ?: 'php');
        $script = escapeshellarg($this->basePath . '/bin/worker.php');
        $token = escapeshellarg(trim((string)file_get_contents($tokenPath)));
        $lockPath = escapeshellarg($path . '/worker.lock');
        // The lock keeps concurrency at one even when several requests launch the CLI simultaneously.
        $command = "$php $script $token $lockPath >/dev/null 2>&1 &";
        exec($command);
    }

    private function tokenPath(): string { return $this->basePath . '/storage/workers/internal.token'; }
}
