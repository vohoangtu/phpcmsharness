<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Infrastructure\Persistence\Sqlite\SqliteWorkerJobQueue;

if (isset($argv[1])) {
    $pdo = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $queue = new SqliteWorkerJobQueue($pdo);
    echo $queue->enqueue('harness_task', ['task_id' => 88]);
    exit;
}
$file = tempnam(sys_get_temp_dir(), 'worker-concurrency-');
if ($file === false) throw new RuntimeException('Cannot create temporary database');
$children = [];
try {
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    new SqliteWorkerJobQueue($pdo);
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start test process');
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    $ids = [];
    foreach ($children as [$process, $pipes]) {
        $ids[] = trim((string) stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException((string) $error);
    }
    if ($ids[0] === '' || $ids[0] !== $ids[1] || (int) $pdo->query('SELECT COUNT(*) FROM worker_jobs')->fetchColumn() !== 1) throw new RuntimeException('Concurrent enqueue created duplicate jobs');
    echo "PASSED: concurrent enqueue creates only one active job\n";
    $pdo = null;
} finally { @unlink($file); }
