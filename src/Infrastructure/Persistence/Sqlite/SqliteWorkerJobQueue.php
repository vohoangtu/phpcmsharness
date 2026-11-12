<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\WorkerJob;
use App\Domain\Port\Outbound\WorkerJobQueuePort;
use PDO;

final readonly class SqliteWorkerJobQueue implements WorkerJobQueuePort
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS worker_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, payload TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'queued\', attempts INTEGER NOT NULL DEFAULT 0,
            run_at TEXT NULL, started_at TEXT NULL, finished_at TEXT NULL,
            error TEXT NULL, result TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_worker_jobs_claim ON worker_jobs(status, id)');
    }

    public function enqueue(string $type, array $payload): int
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($type === 'harness_task') {
                $stmt = $this->pdo->prepare("SELECT id FROM worker_jobs WHERE type='harness_task' AND status IN ('queued','running') AND CAST(json_extract(payload, '$.task_id') AS INTEGER) = CAST(? AS INTEGER) ORDER BY id LIMIT 1");
                $stmt->execute([(int) ($payload['task_id'] ?? 0)]);
                $existing = $stmt->fetchColumn();
                if ($existing !== false) { $this->pdo->commit(); return (int) $existing; }
            }
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare('INSERT INTO worker_jobs(type,payload,status,created_at,updated_at) VALUES(?,?,\'queued\',?,?)');
            $stmt->execute([$type, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function hasActiveHarnessTask(int $taskId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM worker_jobs WHERE type='harness_task' AND status IN ('queued','running') AND CAST(json_extract(payload, '$.task_id') AS INTEGER) = CAST(? AS INTEGER) LIMIT 1");
        $stmt->execute([$taskId]);
        return (bool) $stmt->fetchColumn();
    }

    public function claimNext(): ?WorkerJob
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $row = $this->pdo->query("SELECT * FROM worker_jobs WHERE status='queued' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->pdo->commit(); return null; }
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare("UPDATE worker_jobs SET status='running', attempts=attempts+1, started_at=?, updated_at=? WHERE id=? AND status='queued'");
            $stmt->execute([$now, $now, $row['id']]);
            $this->pdo->commit();
            $row['status'] = 'running'; $row['attempts'] = (int) $row['attempts'] + 1; $row['started_at'] = $now;
            return $this->hydrate($row);
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function recoverRunning(): void
    {
        $this->pdo->exec("UPDATE worker_jobs SET status='queued', started_at=NULL, updated_at=datetime('now') WHERE status='running'");
    }

    public function finish(int $id, string $status, ?string $result, ?string $error): void
    {
        $stmt = $this->pdo->prepare('UPDATE worker_jobs SET status=?, result=?, error=?, finished_at=?, updated_at=? WHERE id=? AND status=\'running\'');
        $now = date('Y-m-d H:i:s'); $stmt->execute([$status, $result, $error, $now, $now, $id]);
    }

    public function retry(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE worker_jobs SET status='queued', error=NULL, result=NULL, run_at=NULL, finished_at=NULL, updated_at=? WHERE id=? AND (status='failed' OR (status='completed' AND json_valid(result) AND json_extract(result, '$.status') IN ('failed','rolled_back'))) AND NOT EXISTS (SELECT 1 FROM worker_jobs active WHERE active.id != worker_jobs.id AND active.type='harness_task' AND active.status IN ('queued','running') AND json_extract(active.payload, '$.task_id') = json_extract(worker_jobs.payload, '$.task_id'))");
        $stmt->execute([date('Y-m-d H:i:s'), $id]); return $stmt->rowCount() === 1;
    }

    public function cancel(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE worker_jobs SET status='cancelled', finished_at=?, updated_at=? WHERE id=? AND status='queued'");
        $now = date('Y-m-d H:i:s'); $stmt->execute([$now, $now, $id]); return $stmt->rowCount() === 1;
    }

    public function list(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM worker_jobs ORDER BY id DESC LIMIT ?'); $stmt->bindValue(1, max(1, min(500, $limit)), PDO::PARAM_INT); $stmt->execute();
        return array_map(fn(array $row): WorkerJob => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function hydrate(array $r): WorkerJob
    {
        return new WorkerJob((int)$r['id'], (string)$r['type'], json_decode((string)$r['payload'], true) ?: [], (string)$r['status'], (int)$r['attempts'], $r['run_at'] ?: null, $r['started_at'] ?: null, $r['finished_at'] ?: null, $r['error'] ?: null, $r['result'] ?: null, (string)$r['created_at'], (string)$r['updated_at']);
    }
}
