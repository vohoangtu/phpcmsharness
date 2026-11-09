<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Entity\HarnessTask;
use App\Domain\Enum\HarnessTaskStatus;
use App\Domain\Port\Outbound\HarnessRepositoryPort;
use PDO;

final readonly class SqliteHarnessRepository implements HarnessRepositoryPort
{
    public function __construct(
        private PDO $pdo
    ) {
        $this->ensureTable();
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM harness_tasks ORDER BY id DESC');
        $rows = $stmt ? $stmt->fetchAll() : [];
        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function findById(int $id): ?HarnessTask
    {
        $stmt = $this->pdo->prepare('SELECT * FROM harness_tasks WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ? $this->hydrate($r) : null;
    }

    public function save(HarnessTask $task): int
    {
        $now = date('Y-m-d H:i:s');
        $filesJson = json_encode(array_values($task->filesChanged), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($task->id === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO harness_tasks (
                    task_code, title, specification, action_type, target_name, agent_backend,
                    status, attempts, max_attempts, snapshot_id, files_changed,
                    verification_output, execution_log, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $task->taskCode,
                $task->title,
                $task->specification,
                $task->actionType,
                $task->targetName,
                $task->agentBackend,
                $task->status->value,
                $task->attempts,
                $task->maxAttempts,
                $task->snapshotId,
                $filesJson,
                $task->verificationOutput,
                $task->executionLog,
                $now,
                $now,
            ]);
            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare('
            UPDATE harness_tasks
            SET title = ?, specification = ?, action_type = ?, target_name = ?, agent_backend = ?,
                status = ?, attempts = ?, max_attempts = ?, snapshot_id = ?, files_changed = ?,
                verification_output = ?, execution_log = ?, updated_at = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $task->title,
            $task->specification,
            $task->actionType,
            $task->targetName,
            $task->agentBackend,
            $task->status->value,
            $task->attempts,
            $task->maxAttempts,
            $task->snapshotId,
            $filesJson,
            $task->verificationOutput,
            $task->executionLog,
            $now,
            $task->id,
        ]);
        return $task->id;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM harness_tasks WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS harness_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_code TEXT NOT NULL,
                title TEXT NOT NULL,
                specification TEXT NOT NULL,
                action_type TEXT NOT NULL DEFAULT "scaffold_module",
                target_name TEXT NOT NULL DEFAULT "",
                agent_backend TEXT NOT NULL DEFAULT "builtin-hexagonal",
                status TEXT NOT NULL DEFAULT "backlog",
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                snapshot_id TEXT NOT NULL DEFAULT "",
                files_changed TEXT NOT NULL DEFAULT "[]",
                verification_output TEXT NOT NULL DEFAULT "",
                execution_log TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM harness_tasks')->fetchColumn();
        if ($count === 0) {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare('
                INSERT INTO harness_tasks (
                    task_code, title, specification, action_type, target_name, agent_backend,
                    status, attempts, max_attempts, snapshot_id, files_changed,
                    verification_output, execution_log, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                'TZY-001',
                'Kiểm toán & Bảo chứng Kiến trúc Hexagonal (Self-Audit Gate)',
                'Quét toàn bộ tầng Domain đảm bảo không phụ thuộc vào PDO/HTTP, kiểm tra cú pháp PHP 8.5 và chạy bộ kiểm thử tích hợp.',
                'self_audit',
                'HexagonCore',
                'builtin-hexagonal',
                'backlog',
                0,
                3,
                '',
                '[]',
                '',
                '',
                $now,
                $now,
            ]);
            $stmt->execute([
                'TZY-002',
                'Tự động sinh phân hệ Hexagonal mới: Coupon (Mã giảm giá)',
                'Tự động sinh Domain Entity Coupon, Outbound Port CouponRepositoryPort, Driven Adapter SqliteCouponRepository, Application Service CouponService và kiểm thử tự động.',
                'scaffold_module',
                'Coupon',
                'builtin-hexagonal',
                'backlog',
                0,
                3,
                '',
                '[]',
                '',
                '',
                $now,
                $now,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): HarnessTask
    {
        $decoded = json_decode((string) ($r['files_changed'] ?? '[]'), true);
        $files = is_array($decoded) ? array_map('strval', $decoded) : [];

        return new HarnessTask(
            id: (int) $r['id'],
            taskCode: (string) $r['task_code'],
            title: (string) $r['title'],
            specification: (string) $r['specification'],
            actionType: (string) $r['action_type'],
            targetName: (string) $r['target_name'],
            agentBackend: (string) $r['agent_backend'],
            status: HarnessTaskStatus::tryFrom((string) $r['status']) ?? HarnessTaskStatus::BACKLOG,
            attempts: (int) $r['attempts'],
            maxAttempts: (int) $r['max_attempts'],
            snapshotId: (string) $r['snapshot_id'],
            filesChanged: $files,
            verificationOutput: (string) $r['verification_output'],
            executionLog: (string) $r['execution_log'],
            createdAt: (string) $r['created_at'],
            updatedAt: (string) $r['updated_at']
        );
    }
}
