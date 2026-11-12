<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Persistence\Sqlite\SqliteWorkerJobQueue;

$pdo = new PDO('sqlite::memory:');
$queue = new SqliteWorkerJobQueue($pdo);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    echo "PASSED: {$message}\n";
};

$cancelId = $queue->enqueue('harness_task', ['task_id' => 41]);
$assert($queue->cancel($cancelId), 'queued job can be cancelled');
$assert(!$queue->cancel($cancelId), 'cancel cannot change a non-queued job');

$jobId = $queue->enqueue('harness_task', ['task_id' => 42]);
$claimed = $queue->claimNext();
$assert($claimed !== null && $claimed->id === $jobId && $claimed->status === 'running' && $claimed->attempts === 1, 'claim marks the next job running and increments attempts');
$assert(!$queue->cancel($jobId), 'running job cannot be cancelled');
$queue->finish($jobId, 'failed', null, 'simulated failure');
$assert($queue->retry($jobId), 'failed job can be returned to queue');
$claimedAgain = $queue->claimNext();
$assert($claimedAgain !== null && $claimedAgain->attempts === 2, 'retried job increments attempts on next claim');
$queue->finish($jobId, 'completed', '{"status":"completed"}', null);
$listed = $queue->list();
$assert($listed[0]->status === 'completed' && $listed[0]->result === '{"status":"completed"}', 'completed job result persists in SQLite');

$recoverId = $queue->enqueue('harness_task', ['task_id' => 43]);
$queue->claimNext();
$queue->recoverRunning();
$recovered = $queue->claimNext();
$assert($recovered !== null && $recovered->id === $recoverId && $recovered->attempts === 2, 'running job is recoverable after worker interruption');
$queue->finish($recoverId, 'completed', '{"status":"failed"}', null);
$assert($queue->retry($recoverId), 'legacy completed job with failed task can be retried');
$duplicateId = $queue->enqueue('harness_task', ['task_id' => 43]);
$assert($duplicateId === $recoverId && $queue->hasActiveHarnessTask(43), 'duplicate active task reuses its queued job');
$assert(!$queue->retry($recoverId), 'a queued job cannot be retried again');
echo "Worker queue checks passed.\n";
