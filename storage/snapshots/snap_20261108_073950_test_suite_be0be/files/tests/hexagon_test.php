<?php

declare(strict_types=1);

use App\Application\Service\CommentService;
use App\Application\Service\ModuleManagerService;
use App\Application\Service\OrderService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Application\Service\TaxonomyService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\CartPort;
use App\Infrastructure\Container\Container;

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

// 0. Verify Media uploads validate file contents, not client-supplied MIME metadata.
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

// 1. Verify Seeded Posts & Products
$postService = $container->get(PostService::class);
$productService = $container->get(ProductService::class);
$moduleManager = $container->get(ModuleManagerService::class);

$posts = $postService->listPosts();
assertTrue(count($posts) >= 2, 'Database seeded with sample blog posts');

$products = $productService->listProducts();
assertTrue(count($products) >= 3, 'Database seeded with sample products');

// 2. Verify Module Toggle & Dependency Rules (Hexagonal Feature Flags)
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

// 3. Verify Creating a Post with Vietnamese Slug & Tags
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

// 4. Verify Creating a Product & Cart Checkout Flow
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

// 5. Verify Tuzygent Autonomous Code Harness (Snapshot + Scaffold + 3-Stage Gate + Rollback)
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

// 6. Verify Tuzygent Continuous Autonomous Mode State & Lifecycle
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
