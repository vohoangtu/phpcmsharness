<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\Service\TuzygentHarnessService;
use App\Domain\Entity\HarnessTask;
use App\Domain\Port\Outbound\HarnessRepositoryPort;

$repo = new class implements HarnessRepositoryPort {
    public function findAll(): array { return []; }
    public function findById(int $id): ?HarnessTask { return null; }
    public function save(HarnessTask $task): int { return 1; }
    public function delete(int $id): void {}
};
$root = sys_get_temp_dir() . '/harness-snapshot-test-' . bin2hex(random_bytes(8));
mkdir($root . '/src', 0770, true);
mkdir($root . '/config', 0770, true);
mkdir($root . '/public/uploads', 0770, true);
file_put_contents($root . '/config/app.php', "<?php return ['db' => ['driver' => 'sqlite', 'sqlite_path' => '/outside/live.sqlite']];");
file_put_contents($root . '/src/example.php', 'original');
file_put_contents($root . '/public/uploads/old.png', 'old image');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASSED: {$message}\n"; };
try {
    $service = new TuzygentHarnessService($repo, $root);
    $createStage = new ReflectionMethod($service, 'createStagingWorkspace');
    $stage = $createStage->invoke($service, 7);
    $stageConfig = require $stage . '/config/app.php';
    $assert($stageConfig['db']['sqlite_path'] === $stage . '/storage/database.sqlite' && !is_file($stage . '/public/uploads/old.png'), 'staging isolates database and uploads');
    $id = $service->createSnapshot('test');
    $manifest = json_decode((string) file_get_contents($root . '/storage/snapshots/' . $id . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(!in_array('public/uploads/old.png', $manifest['files'], true), 'snapshot excludes uploads');
    file_put_contents($root . '/public/uploads/new.png', 'new image');
    file_put_contents($root . '/src/example.php', 'changed');
    $assert($service->rollbackToSnapshot($id), 'snapshot restores');
    $assert(file_get_contents($root . '/src/example.php') === 'original' && file_get_contents($root . '/public/uploads/new.png') === 'new image', 'rollback restores source and preserves new uploads');
    $manifest['files'][] = 'public/uploads/old.png';
    file_put_contents($root . '/storage/snapshots/' . $id . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($root . '/public/uploads/old.png', 'changed image');
    $assert($service->rollbackToSnapshot($id) && file_get_contents($root . '/public/uploads/old.png') === 'changed image', 'legacy snapshots never overwrite uploads');
    file_put_contents($root . '/src/example.php', 'current');
    file_put_contents($root . '/storage/snapshots/' . $id . '/files/src/example.php', 'damaged');
    $assert(!$service->rollbackToSnapshot($id) && file_get_contents($root . '/src/example.php') === 'current', 'corrupt snapshot rejected before changing source');
    file_put_contents($root . '/storage/update-maintenance.json', json_encode(['snapshot_id' => $id]));
    $assert(!$service->recoverInterruptedUpdate() && is_file($root . '/storage/update-maintenance.json'), 'failed recovery retains maintenance');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($root);
}
