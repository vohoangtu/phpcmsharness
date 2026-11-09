<?php

declare(strict_types=1);

use App\Application\Service\CommentService;
use App\Application\Service\CmsTopicGenerator;
use App\Application\Service\ModuleManagerService;
use App\Application\Service\OrderService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Application\Service\TaxonomyService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\CommentStatus;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\CartPort;
use App\Infrastructure\Container\Container;
use App\Infrastructure\Persistence\Sqlite\SqliteCommentRepository;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$config = require dirname(__DIR__) . '/config/app.php';
$container = new Container($config);

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "❌ FAILED: {$msg}\n");
        exit(1);
    }
    echo "✅ PASSED: {$msg}\n";
}

$_SERVER['HTTPS'] = 'on';
\App\Presentation\Http\SessionBootstrap::start();
unset($_SERVER['HTTPS']);
$sessionCookieParams = session_get_cookie_params();
assertTrue(ini_get('session.use_strict_mode') === '1', 'Session bootstrap rejects client-supplied uninitialized session IDs');
assertTrue(ini_get('session.use_only_cookies') === '1' && $sessionCookieParams['httponly'] && $sessionCookieParams['secure'], 'Session bootstrap restricts HTTPS session cookies to HttpOnly and Secure transport');
assertTrue(strtolower((string) $sessionCookieParams['samesite']) === 'lax', 'Session bootstrap sets an explicit SameSite=Lax policy');
$_SESSION = [];
$csrfToken = \App\Presentation\Http\Csrf::token();
assertTrue((bool) preg_match('/^[a-f0-9]{64}$/', $csrfToken), 'CSRF token is generated as a cryptographically random 256-bit hex value');
assertTrue(\App\Presentation\Http\Csrf::isValid($csrfToken), 'CSRF token accepts the matching session token');
assertTrue(!\App\Presentation\Http\Csrf::isValid('invalid-token'), 'CSRF token rejects a mismatched submitted value');

assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/cart') === '/cart', 'Local redirect accepts an internal path');
assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/shop?category=books&page=2') === '/shop?category=books&page=2', 'Local redirect preserves an internal path and query');
assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/admin/modules?filter=enabled', '/admin/modules') === '/admin/modules?filter=enabled', 'Local redirect preserves a valid module admin return path');
assertTrue(\App\Presentation\Http\LocalRedirect::resolve('https://evil.example', '/admin/modules') === '/admin/modules', 'Local redirect uses the module admin fallback for an external destination');
foreach (['https://evil.example', '//evil.example/path', '///evil.example', 'javascript:alert(1)', '/\\\\evil.example', "/cart\r\nLocation: https://evil.example"] as $unsafeRedirect) {
    assertTrue(\App\Presentation\Http\LocalRedirect::resolve($unsafeRedirect) === '/cart', 'Local redirect rejects an external or malformed destination');
}

// 0. Verify AutoTopic identities survive unrelated line-number shifts.
$strictTypesTestRoot = sys_get_temp_dir() . '/tuzygent-topics-strict-' . bin2hex(random_bytes(6));
mkdir($strictTypesTestRoot . '/src', 0775, true);
mkdir($strictTypesTestRoot . '/tests', 0775, true);
file_put_contents($strictTypesTestRoot . '/src/MissingTypes.php', "<?php\n// declare(strict_types=1); in a comment is not a directive.\n\$example = 'declare(strict_types=1);';\n");
file_put_contents($strictTypesTestRoot . '/src/StrictTypes.php', "<?php\ndeclare(strict_types=1);\n\$value = 1;\n");
file_put_contents($strictTypesTestRoot . '/src/LateStrictTypes.php', "<?php\n\$value = 1;\ndeclare(strict_types=1);\n");
file_put_contents($strictTypesTestRoot . '/src/ScopedStrictTypes.php', "<?php\nif (true) { declare(strict_types=1); }\n");
file_put_contents($strictTypesTestRoot . '/tests/fixture_test.php', "<?php\ndeclare(strict_types=1);\n");
$strictTypeTopics = (new CmsTopicGenerator($strictTypesTestRoot))->generate();
$strictTypeTopic = array_values(array_filter($strictTypeTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsStrictTypes' . substr(sha1('src/MissingTypes.php'), 0, 12)));
assertTrue(count($strictTypeTopic) === 1 && $strictTypeTopic[0]['priority'] === 'P0', 'AutoTopic finds a PHP file without strict types while ignoring compliant files and directive-like text');
$lateStrictTypeTarget = 'CmsStrictTypes' . substr(sha1('src/LateStrictTypes.php'), 0, 12);
$scopedStrictTypeTarget = 'CmsStrictTypes' . substr(sha1('src/ScopedStrictTypes.php'), 0, 12);
assertTrue(
    count(array_filter($strictTypeTopics, static fn(array $topic): bool => in_array($topic['target_name'], [$lateStrictTypeTarget, $scopedStrictTypeTarget], true))) === 2,
    'AutoTopic requires strict_types to be the first file-level statement'
);
$suppressedStrictTypeTopics = (new CmsTopicGenerator($strictTypesTestRoot))->generate([strtolower($strictTypeTopic[0]['target_name']) => true]);
assertTrue(!array_filter($suppressedStrictTypeTopics, static fn(array $topic): bool => $topic['target_name'] === $strictTypeTopic[0]['target_name']), 'AutoTopic suppresses a previously discovered strict-types target');
foreach (glob($strictTypesTestRoot . '/src/*.php') ?: [] as $strictFile) {
    unlink($strictFile);
}
unlink($strictTypesTestRoot . '/tests/fixture_test.php');
rmdir($strictTypesTestRoot . '/src');
rmdir($strictTypesTestRoot . '/tests');
rmdir($strictTypesTestRoot);

// AutoTopic discovers standalone comment markers in frontend JS and CSS assets.
$assetTopicRoot = sys_get_temp_dir() . '/tuzygent-topic-assets-' . bin2hex(random_bytes(6));
mkdir($assetTopicRoot . '/public/assets/js', 0775, true);
mkdir($assetTopicRoot . '/public/assets/css', 0775, true);
file_put_contents($assetTopicRoot . '/public/assets/js/app.js', <<<'JS'
const help = "// TODO: This is string content, not work.";
const example = `
// FIXME: This is template content, not work.
`;
// TODO: Restore keyboard navigation.
JS
);
file_put_contents($assetTopicRoot . '/public/assets/css/app.css', <<<'CSS'
.sample::before { content: "TODO: This is CSS string content."; }
/*
 * FIXME: Restore focus indicator.
 */
CSS
);
$assetTopics = (new CmsTopicGenerator($assetTopicRoot))->generate();
$assetTodoTarget = 'CmsTodo' . substr(sha1('public/assets/js/app.js:TODO:restore keyboard navigation.'), 0, 12);
$assetFixmeTarget = 'CmsTodo' . substr(sha1('public/assets/css/app.css:FIXME:restore focus indicator.'), 0, 12);
assertTrue(
    count($assetTopics) === 2
        && array_column($assetTopics, 'target_name') === [$assetFixmeTarget, $assetTodoTarget],
    'AutoTopic finds standalone JavaScript and CSS comment markers while ignoring marker-like strings'
);
file_put_contents($assetTopicRoot . '/public/assets/js/app.js', "// TODO: Restore keyboard navigation.\n" . file_get_contents($assetTopicRoot . '/public/assets/js/app.js'));
$shiftedAssetTopics = (new CmsTopicGenerator($assetTopicRoot))->generate();
assertTrue(
    count(array_filter($shiftedAssetTopics, static fn(array $topic): bool => $topic['target_name'] === $assetTodoTarget)) === 1,
    'AutoTopic asset marker identity remains stable when unrelated lines shift'
);
foreach (glob($assetTopicRoot . '/public/assets/js/*.js') ?: [] as $assetFile) {
    unlink($assetFile);
}
foreach (glob($assetTopicRoot . '/public/assets/css/*.css') ?: [] as $assetFile) {
    unlink($assetFile);
}
rmdir($assetTopicRoot . '/public/assets/js');
rmdir($assetTopicRoot . '/public/assets/css');
rmdir($assetTopicRoot . '/public/assets');
rmdir($assetTopicRoot . '/public');
rmdir($assetTopicRoot);

// AutoTopic must keep resolved symlink targets inside the repository root.
$symlinkTestRoot = sys_get_temp_dir() . '/tuzygent-topics-symlink-' . bin2hex(random_bytes(6));
$symlinkOutsideRoot = sys_get_temp_dir() . '/tuzygent-topics-outside-' . bin2hex(random_bytes(6));
mkdir($symlinkTestRoot . '/src', 0775, true);
mkdir($symlinkOutsideRoot, 0775, true);
$outsidePhpFile = $symlinkOutsideRoot . '/MissingTypes.php';
file_put_contents($outsidePhpFile, "<?php\n\$outside = true;\n");
$externalPhpLink = $symlinkTestRoot . '/src/External.php';
assertTrue(symlink($outsidePhpFile, $externalPhpLink), 'AutoTopic symlink fixture created');
$externalTargetName = 'CmsStrictTypes' . substr(sha1('src/External.php'), 0, 12);
$externalFileTopics = (new CmsTopicGenerator($symlinkTestRoot))->generate();
assertTrue(
    !array_filter($externalFileTopics, static fn(array $topic): bool => $topic['target_name'] === $externalTargetName),
    'AutoTopic ignores PHP symlinks whose canonical target is outside the repository'
);
unlink($externalPhpLink);
unlink($outsidePhpFile);
rmdir($symlinkTestRoot . '/src');
rmdir($symlinkTestRoot);
rmdir($symlinkOutsideRoot);

$topicTestRoot = sys_get_temp_dir() . '/tuzygent-topics-' . bin2hex(random_bytes(6));
mkdir($topicTestRoot . '/src', 0775, true);
mkdir($topicTestRoot . '/src/Application/Service', 0775, true);
mkdir($topicTestRoot . '/tests', 0775, true);
$topicFixture = $topicTestRoot . '/src/Markers.php';
$topicSource = <<<'PHP'
<?php
declare(strict_types=1);

// TODO: Keep the stable marker.
// FIXME: A separate marker.
// TODO: Keep the stable marker.
PHP;
file_put_contents($topicFixture, $topicSource);
$topicGenerator = new CmsTopicGenerator($topicTestRoot);
$initialTopics = $topicGenerator->generate();
assertTrue(count($initialTopics) === 2, 'AutoTopic discovery deduplicates identical source markers while preserving distinct work');
$fixtureService = $topicTestRoot . '/src/Application/Service/FixtureService.php';
file_put_contents($fixtureService, "<?php\ndeclare(strict_types=1);\nfinal class FixtureService {}\n");
$fixtureTest = $topicTestRoot . '/tests/fixture_test.php';
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\n// FixtureService is mentioned in this comment only.\n\$description = 'FixtureService';\n");
$uncoveredTopics = $topicGenerator->generate();
assertTrue(count(array_filter($uncoveredTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat service names in comments or string literals as test coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\n\$fixture->FixtureService();\n");
$methodNameTopics = $topicGenerator->generate();
assertTrue(count(array_filter($methodNameTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat an unrelated method identifier as service coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nnamespace Tests;\nclass FixtureService {}\nnew FixtureService();\n");
$sameNamespaceNameTopics = $topicGenerator->generate();
assertTrue(count(array_filter($sameNamespaceNameTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat an unimported same-name class as Application Service coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nnamespace Tests;\nuse Other\\FixtureService;\nnew FixtureService();\n");
$otherNamespaceNameTopics = $topicGenerator->generate();
assertTrue(count(array_filter($otherNamespaceNameTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat an imported same-name class from another namespace as service coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nuse App\\Application\\Service\\FixtureService as Subject;\nnew Subject();\n");
$aliasedTopics = $topicGenerator->generate();
assertTrue(count(array_filter($aliasedTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic resolves an aliased service import when detecting test coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nuse App\\Application\\Service\\{FixtureService as Subject};\nnew Subject();\n");
$groupAliasedTopics = $topicGenerator->generate();
assertTrue(count(array_filter($groupAliasedTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic resolves a grouped aliased service import when detecting test coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nnew \\App\\Application\\Service\\FixtureService();\n");
$coveredTopics = $topicGenerator->generate();
assertTrue(count(array_filter($coveredTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic recognizes a service referenced by a qualified PHP class name in tests');
$stableTopic = $initialTopics[0]['target_name'];
file_put_contents($topicFixture, "<?php\n\n\n" . substr($topicSource, strlen("<?php\n")));
$shiftedTopics = $topicGenerator->generate();
assertTrue($shiftedTopics[0]['target_name'] === $stableTopic, 'AutoTopic identity remains stable when unrelated lines shift marker positions');
$remainingTopics = $topicGenerator->generate([strtolower($stableTopic) => true]);
assertTrue(count($remainingTopics) === 1 && $remainingTopics[0]['target_name'] !== $stableTopic, 'AutoTopic history suppresses an already discovered marker after line shifts');
unlink($topicFixture);
unlink($fixtureService);
unlink($fixtureTest);
rmdir($topicTestRoot . '/src/Application/Service');
rmdir($topicTestRoot . '/src/Application');
rmdir($topicTestRoot . '/tests');
rmdir($topicTestRoot . '/src');
rmdir($topicTestRoot);

// 0.0.0.1. AutoTopic uses the nearest-rank P90 cutoff for large source files.
$outlierTestRoot = sys_get_temp_dir() . '/tuzygent-topic-outliers-' . bin2hex(random_bytes(6));
mkdir($outlierTestRoot . '/src', 0775, true);
for ($fileIndex = 1; $fileIndex <= 10; $fileIndex++) {
    $lineCount = match ($fileIndex) {
        9 => 200,
        10 => 300,
        default => 10,
    };
    $source = "<?php\ndeclare(strict_types=1);\n";
    for ($line = 1; $line < $lineCount; $line++) {
        $source .= '$value' . $line . ' = ' . $line . ";\n";
    }
    file_put_contents(sprintf('%s/src/File%02d.php', $outlierTestRoot, $fileIndex), $source);
}
$outlierTopics = (new CmsTopicGenerator($outlierTestRoot))->generate();
$largeFileTarget = 'CmsRefactor' . substr(sha1('src/File10.php'), 0, 12);
assertTrue(
    count($outlierTopics) === 1 && $outlierTopics[0]['target_name'] === $largeFileTarget,
    'AutoTopic nearest-rank P90 discovers a lone large-file outlier in a ten-file source sample'
);
foreach (glob($outlierTestRoot . '/src/*.php') ?: [] as $outlierFile) {
    unlink($outlierFile);
}
rmdir($outlierTestRoot . '/src');
rmdir($outlierTestRoot);

// 0.0.0.1.1. AutoTopic must not classify comment-heavy files as large source outliers.
$commentOutlierRoot = sys_get_temp_dir() . '/tuzygent-topic-comment-outlier-' . bin2hex(random_bytes(6));
mkdir($commentOutlierRoot . '/src', 0775, true);
for ($fileIndex = 1; $fileIndex <= 10; $fileIndex++) {
    $lineCount = $fileIndex === 10 ? 300 : 10;
    $source = "<?php\ndeclare(strict_types=1);\n";
    if ($fileIndex === 10) {
        $source .= str_repeat("// documentation only\n", $lineCount - 1);
    } else {
        for ($line = 1; $line < $lineCount; $line++) {
            $source .= '$value' . $line . ' = ' . $line . ";\n";
        }
    }
    file_put_contents(sprintf('%s/src/File%02d.php', $commentOutlierRoot, $fileIndex), $source);
}
$commentOutlierTopics = (new CmsTopicGenerator($commentOutlierRoot))->generate();
assertTrue($commentOutlierTopics === [], 'AutoTopic excludes comment-only lines from large-source outlier measurements');
foreach (glob($commentOutlierRoot . '/src/*.php') ?: [] as $outlierFile) {
    unlink($outlierFile);
}
rmdir($commentOutlierRoot . '/src');
rmdir($commentOutlierRoot);

// 0.0.0.2. AutoTopic caps each discovery batch and leaves deferred targets discoverable.
$batchTestRoot = sys_get_temp_dir() . '/tuzygent-topic-batch-' . bin2hex(random_bytes(6));
mkdir($batchTestRoot . '/src', 0775, true);
mkdir($batchTestRoot . '/src/Application/Service', 0775, true);
$batchMarkers = [];
$batchSource = "<?php\ndeclare(strict_types=1);\n";
for ($markerIndex = 1; $markerIndex <= 12; $markerIndex++) {
    $batchSource .= sprintf("// TODO: Batch fixture %02d.\n", $markerIndex);
}
file_put_contents($batchTestRoot . '/src/Markers.php', $batchSource);
file_put_contents($batchTestRoot . '/src/Application/Service/DeferredService.php', "<?php\ndeclare(strict_types=1);\nfinal class DeferredService {}\n");
$batchGenerator = new CmsTopicGenerator($batchTestRoot);
$firstBatch = $batchGenerator->generate();
assertTrue(
    count($firstBatch) === 10 && count(array_filter($firstBatch, static fn(array $topic): bool => $topic['priority'] !== 'P0')) === 0,
    'AutoTopic limits a discovery batch to ten highest-priority source targets'
);
foreach ($firstBatch as $topic) {
    $batchMarkers[strtolower($topic['target_name'])] = true;
}
$deferredBatch = $batchGenerator->generate($batchMarkers);
assertTrue(
    count($deferredBatch) === 3
        && count(array_filter($deferredBatch, static fn(array $topic): bool => $topic['priority'] === 'P0')) === 2
        && count(array_filter($deferredBatch, static fn(array $topic): bool => $topic['priority'] === 'P1')) === 1,
    'AutoTopic exposes deferred source targets on a later discovery cycle'
);
unlink($batchTestRoot . '/src/Markers.php');
unlink($batchTestRoot . '/src/Application/Service/DeferredService.php');
rmdir($batchTestRoot . '/src/Application/Service');
rmdir($batchTestRoot . '/src/Application');
rmdir($batchTestRoot . '/src');
rmdir($batchTestRoot);

// 0.0.1. AutoTopic should use the highest-priority supported CLI available on PATH.
$backendTestRoot = sys_get_temp_dir() . '/tuzygent-backends-' . bin2hex(random_bytes(6));
$fakeBin = $backendTestRoot . '/bin';
mkdir($fakeBin, 0775, true);
$previousPath = getenv('PATH');
$previousPath = $previousPath === false ? '' : $previousPath;
foreach (['claude', 'opencode'] as $fakeBackend) {
    $fakeBinary = $fakeBin . '/' . $fakeBackend;
    file_put_contents($fakeBinary, "#!/bin/sh\nexit 0\n");
    chmod($fakeBinary, 0775);
}
putenv('PATH=' . $fakeBin);
$backendHarness = new \App\Application\Service\TuzygentHarnessService(
    new \App\Infrastructure\Persistence\Sqlite\SqliteHarnessRepository(new PDO('sqlite::memory:')),
    $backendTestRoot
);
$autoTopicId = $backendHarness->discoverNextEvolutionTask();
$autoTopic = $autoTopicId === null ? null : $backendHarness->getTask($autoTopicId);
assertTrue($autoTopic !== null && $autoTopic->targetName === 'AutoTopic' && $autoTopic->agentBackend === 'claude', 'AutoTopic selects the first supported CLI present when Codex is unavailable');
putenv('PATH=' . $previousPath);
unlink($fakeBin . '/claude');
unlink($fakeBin . '/opencode');
rmdir($fakeBin);
rmdir($backendTestRoot . '/storage/snapshots');
rmdir($backendTestRoot . '/storage');
rmdir($backendTestRoot . '/.harness');
rmdir($backendTestRoot);

// 0.0.2. Harness task codes follow SQLite's durable, non-reused AUTOINCREMENT ID.
$taskCodeTestRoot = sys_get_temp_dir() . '/tuzygent-task-codes-' . bin2hex(random_bytes(6));
$taskCodeRepository = new \App\Infrastructure\Persistence\Sqlite\SqliteHarnessRepository(new PDO('sqlite::memory:'));
$taskCodeHarness = new \App\Application\Service\TuzygentHarnessService($taskCodeRepository, $taskCodeTestRoot);
$firstCreatedTaskId = $taskCodeHarness->createTask(['title' => 'Task code fixture 1']);
$firstCreatedTask = $taskCodeHarness->getTask($firstCreatedTaskId);
$taskCodeHarness->deleteTask(2);
$secondCreatedTaskId = $taskCodeHarness->createTask(['title' => 'Task code fixture 2']);
$secondCreatedTask = $taskCodeHarness->getTask($secondCreatedTaskId);
assertTrue(
    $firstCreatedTask?->taskCode === 'TZY-003'
        && $secondCreatedTask?->taskCode === 'TZY-004'
        && $firstCreatedTask->taskCode !== $secondCreatedTask->taskCode,
    'Harness task codes follow durable SQLite IDs and do not collide after deleting an older task'
);
rmdir($taskCodeTestRoot . '/storage/snapshots');
rmdir($taskCodeTestRoot . '/storage');
rmdir($taskCodeTestRoot . '/.harness');
rmdir($taskCodeTestRoot);

// 0.1. Verify comment pagination applies stable ordering and matching filters to list/count queries.
$commentDbPath = sys_get_temp_dir() . '/tuzygent-comments-' . bin2hex(random_bytes(6)) . '.sqlite';
$commentPdo = new PDO('sqlite:' . $commentDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$commentPdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, commentable_type TEXT NOT NULL, commentable_id INTEGER NOT NULL, author_name TEXT NOT NULL, author_email TEXT NOT NULL, content TEXT NOT NULL, rating INTEGER NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL)');
$commentPdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$commentPdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
$commentInsert = $commentPdo->prepare('INSERT INTO comments (commentable_type, commentable_id, author_name, author_email, content, rating, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
for ($commentIndex = 1; $commentIndex <= 25; $commentIndex++) {
    $commentInsert->execute([
        $commentIndex % 2 === 0 ? 'product' : 'post',
        $commentIndex,
        'Author ' . $commentIndex,
        'author@example.test',
        'Comment ' . $commentIndex,
        5,
        $commentIndex % 3 === 0 ? 'pending' : 'approved',
        '2026-01-01 00:00:00',
    ]);
}
$commentRepository = new SqliteCommentRepository($commentPdo);
$firstCommentPage = $commentRepository->findAll(null, null, 20, 0);
$secondCommentPage = $commentRepository->findAll(null, null, 20, 20);
assertTrue(count($firstCommentPage) === 20 && count($secondCommentPage) === 5, 'Comment repository returns bounded pages using LIMIT and OFFSET');
assertTrue($firstCommentPage[0]->id === 25 && $secondCommentPage[0]->id === 5, 'Comment pagination preserves descending ID order across pages');
assertTrue($commentRepository->countMatching(CommentStatus::PENDING, 'product') === 4 && count($commentRepository->findAll(CommentStatus::PENDING, 'product', 20, 0)) === 4, 'Comment count and page queries apply the same status and type filters');
$commentPdo = null;
unlink($commentDbPath);

// 1. Verify Media uploads validate file contents, not client-supplied MIME metadata.
$mediaTestRoot = sys_get_temp_dir() . '/tuzygent-media-' . bin2hex(random_bytes(6));
$mediaUploadsDir = $mediaTestRoot . '/uploads';
mkdir($mediaTestRoot, 0775, true);
$validImagePath = $mediaTestRoot . '/valid.png';
$spoofedImagePath = $mediaTestRoot . '/spoofed.jpg';
$svgImagePath = $mediaTestRoot . '/active.svg';
$oversizedImagePath = $mediaTestRoot . '/oversized.png';
file_put_contents($validImagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=', true));
file_put_contents($spoofedImagePath, '<?php echo "not an image";');
file_put_contents($svgImagePath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
file_put_contents($oversizedImagePath, str_repeat('x', 5 * 1024 * 1024 + 1));
$mediaStorage = new \App\Infrastructure\Storage\LocalMediaStorageAdapter($mediaUploadsDir);
$storedImage = $mediaStorage->storeUploadedFile([
    'name' => 'pixel.png',
    'tmp_name' => $validImagePath,
    'type' => 'text/plain',
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($validImagePath),
]);
assertTrue($storedImage->mimeType === 'image/png' && is_file($mediaUploadsDir . '/' . $storedImage->filename), 'Media upload detects image MIME from file contents instead of trusting client metadata');

$spoofedUploadRejected = false;
try {
    $mediaStorage->storeUploadedFile([
        'name' => 'payload.jpg',
        'tmp_name' => $spoofedImagePath,
        'type' => 'image/jpeg',
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($spoofedImagePath),
    ]);
} catch (RuntimeException) {
    $spoofedUploadRejected = true;
}
assertTrue($spoofedUploadRejected && count(array_diff(scandir($mediaUploadsDir) ?: [], ['.', '..'])) === 1, 'Media upload rejects non-image content with a forged image extension and MIME');
$svgUploadRejected = false;
try {
    $mediaStorage->storeUploadedFile([
        'name' => 'active.svg',
        'tmp_name' => $svgImagePath,
        'type' => 'image/svg+xml',
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($svgImagePath),
    ]);
} catch (RuntimeException) {
    $svgUploadRejected = true;
}
assertTrue($svgUploadRejected, 'Media upload rejects SVG files that could contain active content');
$oversizedUploadRejected = false;
try {
    $mediaStorage->storeUploadedFile([
        'name' => 'oversized.png',
        'tmp_name' => $oversizedImagePath,
        'type' => 'image/png',
        'error' => UPLOAD_ERR_OK,
        'size' => 1,
    ]);
} catch (RuntimeException $error) {
    $oversizedUploadRejected = str_contains($error->getMessage(), '5 MiB');
}
assertTrue($oversizedUploadRejected && count(array_diff(scandir($mediaUploadsDir) ?: [], ['.', '..'])) === 1, 'Media upload enforces the server-side 5 MiB limit using the temporary file size before storing it');
$mediaStorage->deletePhysicalFile($storedImage->filename);
unlink($validImagePath);
unlink($spoofedImagePath);
unlink($svgImagePath);
unlink($oversizedImagePath);
rmdir($mediaUploadsDir);
rmdir($mediaTestRoot);

// 2. Verify Seeded Posts & Products
$postService = $container->get(PostService::class);
$productService = $container->get(ProductService::class);
$moduleManager = $container->get(ModuleManagerService::class);

$posts = $postService->listPosts();
assertTrue(count($posts) >= 2, 'Database seeded with sample blog posts');
$publishedPostCount = $postService->countPosts(ContentStatus::PUBLISHED);
$postPage = $postService->listPosts(ContentStatus::PUBLISHED, null, '', 1, 1);
assertTrue($publishedPostCount >= 2 && count($postPage) === 1 && $postPage[0]->id !== $posts[0]->id, 'Post listing supports filtered counts and a stable limit/offset page');

$products = $productService->listProducts();
assertTrue(count($products) >= 3, 'Database seeded with sample products');
$publishedProductCount = $productService->countProducts(ContentStatus::PUBLISHED);
$productPage = $productService->listProducts(ContentStatus::PUBLISHED, null, '', null, 1, 1);
assertTrue($publishedProductCount >= 2 && count($productPage) === 1 && $productPage[0]->id !== $products[0]->id, 'Product listing supports filtered counts and a stable limit/offset page');

// 3. Verify Module Toggle & Dependency Rules (Hexagonal Feature Flags)
assertTrue($moduleManager->isEnabled(ModuleKey::PRODUCTS), 'PRODUCTS module is initially enabled');
assertTrue($moduleManager->isEnabled(ModuleKey::ORDERS), 'ORDERS module is initially enabled');

// Disable PRODUCTS -> should automatically disable ORDERS (dependent module)
$moduleManager->setModuleState(ModuleKey::PRODUCTS, false);
assertTrue(!$moduleManager->isEnabled(ModuleKey::PRODUCTS), 'PRODUCTS module is disabled');
assertTrue(!$moduleManager->isEnabled(ModuleKey::ORDERS), 'ORDERS module automatically disabled when PRODUCTS is disabled');

// Enable ORDERS -> should automatically re-enable PRODUCTS (parent dependency)
$moduleManager->setModuleState(ModuleKey::ORDERS, true);
assertTrue($moduleManager->isEnabled(ModuleKey::ORDERS), 'ORDERS module is re-enabled');
assertTrue($moduleManager->isEnabled(ModuleKey::PRODUCTS), 'PRODUCTS module automatically re-enabled when ORDERS is enabled');

// 4. Verify Creating a Post with Vietnamese Slug & Tags
$slugTestSuffix = bin2hex(random_bytes(4));
$newPostId = $postService->savePost([
    'title' => 'Kiểm thử tạo Bài viết tự động bằng Tiếng Việt ' . $slugTestSuffix,
    'excerpt' => 'Tóm tắt bài viết kiểm thử',
    'content' => '<p>Nội dung bài viết kiểm thử Hexagonal Architecture.</p>',
    'status' => ContentStatus::PUBLISHED->value,
    'tags' => 'Testing, Hexagonal, PHP',
], 1);
$createdPost = $postService->getById($newPostId);
assertTrue($createdPost !== null && $createdPost->slug === 'kiem-thu-tao-bai-viet-tu-dong-bang-tieng-viet-' . $slugTestSuffix, 'Vietnamese slug generated accurately for Post');
assertTrue(in_array('Hexagonal', $createdPost->tags, true), 'Tags synced for Post');
$postService->deletePermanently($newPostId);

// 5. Verify Creating a Product & Cart Checkout Flow
$newProductId = $productService->saveProduct([
    'name' => 'Chuột Không Dây Logitech MX Master 3S',
    'price' => 2490000,
    'sale_price' => 2190000,
    'sku' => 'LOGI-MX3S',
    'stock_quantity' => 10,
    'status' => ContentStatus::PUBLISHED->value,
    'tags' => 'Mouse, Logitech',
]);
$createdProd = $productService->getById($newProductId);
assertTrue($createdProd !== null && $createdProd->isOnSale() && $createdProd->effectivePrice() === 2190000.0, 'Product created with sale price calculation');

$cart = $container->get(CartPort::class);
$cart->clear();
$cart->add($newProductId, 2);

$orderService = $container->get(OrderService::class);
$order = $orderService->checkout([
    'customer_name' => 'Khách Hàng Test',
    'customer_email' => 'test@example.com',
    'customer_phone' => '0909000111',
    'customer_address' => '123 Đường Lê Lợi, Quận 1',
    'note' => 'Giao nhanh',
]);

assertTrue($order->totalAmount === 4380000.0, 'Order total calculated accurately from sale price * quantity');
$updatedProd = $productService->getById($newProductId);
assertTrue($updatedProd !== null && $updatedProd->stockQuantity === 8, 'Product stock decremented after checkout');
$productService->deletePermanently($newProductId);

// 6. Verify Tuzygent Autonomous Code Harness (Snapshot + Scaffold + 3-Stage Gate + Rollback)
$harnessService = $container->get(\App\Application\Service\TuzygentHarnessService::class);
$agents = $harnessService->discoverAgents();
assertTrue(isset($agents['builtin-hexagonal']) && $agents['builtin-hexagonal']['available'] === true, 'Tuzygent Built-in Hexagonal Agent available');

$snapBefore = $harnessService->createSnapshot('test_suite');
$generatedFiles = $harnessService->scaffoldHexagonalVerticalSlice('Brand', 'Thương hiệu sản phẩm');
assertTrue(count($generatedFiles) === 4, 'Tuzygent scaffolded 4 Hexagonal vertical slice files (Entity, Port, SQLite Adapter, Service)');
assertTrue(is_file(dirname(__DIR__) . '/src/Domain/Entity/Brand.php'), 'Generated Brand Entity exists');

// Verify restored state via Rollback
$rolledBack = $harnessService->rollbackToSnapshot($snapBefore);
assertTrue($rolledBack && !is_file(dirname(__DIR__) . '/src/Domain/Entity/Brand.php'), 'Snapshot Rollback cleanly restored source tree and removed generated slice');

// 7. Verify Tuzygent Continuous Autonomous Mode State & Lifecycle
$prevContinuousState = $harnessService->getContinuousState();
$startedContinuous = $harnessService->startContinuousMode([
    'interval_seconds' => 5,
    'auto_discover' => true,
    'auto_heal_on_drift' => true,
    'spawn_daemon' => false,
]);
assertTrue($startedContinuous['enabled'] === true && $startedContinuous['status'] === 'running', 'Tuzygent Continuous Mode starts and persists running state');
$stoppedContinuous = $harnessService->stopContinuousMode(false);
assertTrue($stoppedContinuous['enabled'] === false && $stoppedContinuous['status'] === 'stopped', 'Tuzygent Continuous Mode stops cleanly');
$harnessService->saveContinuousState($prevContinuousState);

echo "\n🎉 ALL HEXAGONAL ARCHITECTURE, MODULE TOGGLE & TUZYGENT HARNESS (INCLUDING CONTINUOUS MODE) TESTS PASSED!\n";
