<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use App\Application\Service\TuzygentHarnessService;
use App\Domain\Entity\HarnessTask;
use App\Domain\Enum\HarnessTaskStatus;
use App\Domain\Port\Outbound\HarnessRepositoryPort;

$repo = new class implements HarnessRepositoryPort {
    public ?HarnessTask $task = null;
    public function findAll(): array { return $this->task ? [$this->task] : []; }
    public function findById(int $id): ?HarnessTask { return $this->task?->id === $id ? $this->task : null; }
    public function save(HarnessTask $task): int { $this->task = $task; return (int) $task->id; }
    public function delete(int $id): void {}
};
$root = sys_get_temp_dir() . '/harness-update-test-' . bin2hex(random_bytes(8));
foreach (['src', 'config', 'storage', 'public/uploads', '.harness/updates/task-1/files/src'] as $dir) mkdir($root . '/' . $dir, 0770, true);
symlink(dirname(__DIR__) . '/vendor', $root . '/vendor');
file_put_contents($root . '/config/app.php', '<?php return ' . var_export(['db' => ['driver' => 'sqlite', 'sqlite_path' => $root . '/storage/database.sqlite']], true) . ';');
file_put_contents($root . '/src/example.php', '<?php return 1;');
file_put_contents($root . '/public/uploads/image.txt', 'image');
$pdo = new PDO('sqlite:' . $root . '/storage/database.sqlite');
$pdo->exec('CREATE TABLE example (id INTEGER PRIMARY KEY, value TEXT); INSERT INTO example VALUES (1, "preserved")');
$pdo = null;
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASSED: {$message}\n"; };
$makeTask = static fn(): HarnessTask => new HarnessTask(id: 1, taskCode: 'TZY-001', title: 'Update', specification: '', actionType: 'agent_prompt', targetName: '', agentBackend: 'openai-compatible', status: HarnessTaskStatus::READY_TO_INSTALL, attempts: 1, maxAttempts: 1, snapshotId: '');
$writePackage = static function (string $newContent, bool $valid) use ($root): void {
    $file = $root . '/.harness/updates/task-1/files/src/example.php';
    file_put_contents($file, $newContent);
    $manifest = ['schema_version' => 1, 'task_id' => 1, 'task_code' => 'TZY-001', 'files' => ['src/example.php' => $valid ? hash_file('sha256', $file) : str_repeat('0', 64)], 'deleted' => [], 'base_files' => ['src/example.php' => hash_file('sha256', $root . '/src/example.php')]];
    file_put_contents($root . '/.harness/updates/task-1/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
};
try {
    $service = new TuzygentHarnessService($repo, $root);
    $repo->task = $makeTask();
    $writePackage('<?php return 2;', true);
    $task = $service->installUpdate(1);
    $assert($task->status === HarnessTaskStatus::COMPLETED && file_get_contents($root . '/src/example.php') === '<?php return 2;', 'valid package installs');
    $repo->task = $makeTask();
    $writePackage('<?php return 3;', false);
    $task = $service->installUpdate(1);
    $pdo = new PDO('sqlite:' . $root . '/storage/database.sqlite');
    $assert($task->status === HarnessTaskStatus::ROLLED_BACK && file_get_contents($root . '/src/example.php') === '<?php return 2;' && $pdo->query('SELECT value FROM example')->fetchColumn() === 'preserved' && file_get_contents($root . '/public/uploads/image.txt') === 'image', 'invalid package restores source and database and preserves uploads');
    $assert(!is_file($root . '/storage/update-maintenance.json'), 'successful rollback clears maintenance');
    $pdo = null;
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($root);
}
