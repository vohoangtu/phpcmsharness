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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
$csrfToken = \App\Presentation\Http\Csrf::token();
assertTrue((bool) preg_match('/^[a-f0-9]{64}$/', $csrfToken), 'CSRF token is generated as a cryptographically random 256-bit hex value');
assertTrue(\App\Presentation\Http\Csrf::isValid($csrfToken), 'CSRF token accepts the matching session token');
assertTrue(!\App\Presentation\Http\Csrf::isValid('invalid-token'), 'CSRF token rejects a mismatched submitted value');

assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/cart') === '/cart', 'Local redirect accepts an internal path');
assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/shop?category=books&page=2') === '/shop?category=books&page=2', 'Local redirect preserves an internal path and query');
foreach (['https://evil.example', '//evil.example/path', '///evil.example', 'javascript:alert(1)', '/\\\\evil.example', "/cart\r\nLocation: https://evil.example"] as $unsafeRedirect) {
    assertTrue(\App\Presentation\Http\LocalRedirect::resolve($unsafeRedirect) === '/cart', 'Local redirect rejects an external or malformed destination');
}

// 0. Verify AutoTopic identities survive unrelated line-number shifts.
$topicTestRoot = sys_get_temp_dir() . '/tuzygent-topics-' . bin2hex(random_bytes(6));
mkdir($topicTestRoot . '/src', 0775, true);
mkdir($topicTestRoot . '/src/Application/Service', 0775, true);
mkdir($topicTestRoot . '/tests', 0775, true);
$topicFixture = $topicTestRoot . '/src/Markers.php';
$topicSource = <<<'PHP'
<?php

// TODO: Keep the stable marker.
// FIXME: A separate marker.
// TODO: Keep the stable marker.
PHP;
file_put_contents($topicFixture, $topicSource);
$topicGenerator = new CmsTopicGenerator($topicTestRoot);
$initialTopics = $topicGenerator->generate();
assertTrue(count($initialTopics) === 2, 'AutoTopic discovery deduplicates identical source markers while preserving distinct work');
$fixtureService = $topicTestRoot . '/src/Application/Service/FixtureService.php';
file_put_contents($fixtureService, "<?php\nfinal class FixtureService {}\n");
$fixtureTest = $topicTestRoot . '/tests/fixture_test.php';
file_put_contents($fixtureTest, "<?php\n// FixtureService is mentioned in this comment only.\n\$description = 'FixtureService';\n");
$uncoveredTopics = $topicGenerator->generate();
assertTrue(count(array_filter($uncoveredTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat service names in comments or string literals as test coverage');
file_put_contents($fixtureTest, "<?php\n\$fixture->FixtureService();\n");
$methodNameTopics = $topicGenerator->generate();
assertTrue(count(array_filter($methodNameTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat an unrelated method identifier as service coverage');
file_put_contents($fixtureTest, "<?php\nuse App\\Application\\Service\\FixtureService as Subject;\nnew Subject();\n");
$aliasedTopics = $topicGenerator->generate();
assertTrue(count(array_filter($aliasedTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic resolves an aliased service import when detecting test coverage');
file_put_contents($fixtureTest, "<?php\nuse App\\Application\\Service\\{FixtureService as Subject};\nnew Subject();\n");
$groupAliasedTopics = $topicGenerator->generate();
assertTrue(count(array_filter($groupAliasedTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic resolves a grouped aliased service import when detecting test coverage');
file_put_contents($fixtureTest, "<?php\nnew \\App\\Application\\Service\\FixtureService();\n");
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
file_put_contents($validImagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=', true));
file_put_contents($spoofedImagePath, '<?php echo "not an image";');
file_put_contents($svgImagePath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
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
$mediaStorage->deletePhysicalFile($storedImage->filename);
unlink($validImagePath);
unlink($spoofedImagePath);
unlink($svgImagePath);
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
