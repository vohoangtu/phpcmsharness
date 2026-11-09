<?php

declare(strict_types=1);

use App\Domain\Enum\ModuleKey;
use App\Infrastructure\Container\Container;
use App\Presentation\Controller\Admin\AuthController;
use App\Presentation\Controller\Admin\CommentController;
use App\Presentation\Controller\Admin\DashboardController;
use App\Presentation\Controller\Admin\HarnessController;
use App\Presentation\Controller\Admin\MediaController;
use App\Presentation\Controller\Admin\ModuleController;
use App\Presentation\Controller\Admin\OrderController;
use App\Presentation\Controller\Admin\PostController;
use App\Presentation\Controller\Admin\ProductController;
use App\Presentation\Controller\Admin\SettingController;
use App\Presentation\Controller\Admin\TaxonomyController;
use App\Presentation\Controller\Frontend\BlogController;
use App\Presentation\Controller\Frontend\CartController;
use App\Presentation\Controller\Frontend\HomeController;
use App\Presentation\Controller\Frontend\ShopController;
use App\Presentation\Http\Router;
use App\Presentation\Http\SecurityHeaders;
use App\Presentation\Http\SessionBootstrap;
use App\Presentation\View\ViewRenderer;

// Serve static files directly when using PHP built-in web server
if (PHP_SAPI === 'cli-server') {
    $urlPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . $urlPath;
    if ($urlPath !== '/' && is_file($file)) {
        return false;
    }
}

// PSR-4 Autoloader (Composer + Built-in Fallback)
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
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
}

SecurityHeaders::send();
SessionBootstrap::start();

$config = require dirname(__DIR__) . '/config/app.php';
$container = new Container($config);
$view = new ViewRenderer($container);
$router = new Router($container, $view);

// ============================================================================
// 1. FRONTEND ROUTES (Driving Adapters)
// ============================================================================
$homeCtrl = new HomeController($container, $view);
$blogCtrl = new BlogController($container, $view);
$shopCtrl = new ShopController($container, $view);
$cartCtrl = new CartController($container, $view);

$router->get('/', [$homeCtrl, 'index']);

// Blog Routes (Protected by ModuleKey::POSTS)
$router->get('/blog', [$blogCtrl, 'index'], ModuleKey::POSTS);
$router->get('/post/{slug}', [$blogCtrl, 'show'], ModuleKey::POSTS);
$router->post('/post/{slug}/comment', [$blogCtrl, 'addComment'], ModuleKey::COMMENTS);

// Shop Routes (Protected by ModuleKey::PRODUCTS)
$router->get('/shop', [$shopCtrl, 'index'], ModuleKey::PRODUCTS);
$router->get('/product/{slug}', [$shopCtrl, 'show'], ModuleKey::PRODUCTS);
$router->post('/product/{slug}/review', [$shopCtrl, 'addReview'], ModuleKey::COMMENTS);

// Cart & Checkout Routes (Protected by ModuleKey::ORDERS)
$router->get('/cart', [$cartCtrl, 'index'], ModuleKey::ORDERS);
$router->post('/cart/add', [$cartCtrl, 'add'], ModuleKey::ORDERS);
$router->post('/cart/update', [$cartCtrl, 'update'], ModuleKey::ORDERS);
$router->post('/cart/remove/{id}', [$cartCtrl, 'remove'], ModuleKey::ORDERS);
$router->post('/checkout', [$cartCtrl, 'checkout'], ModuleKey::ORDERS);

// ============================================================================
// 2. ADMIN ROUTES (WP-Admin Style + Module Toggle Management)
// ============================================================================
$authCtrl = new AuthController($container, $view);
$dashCtrl = new DashboardController($container, $view);
$modCtrl = new ModuleController($container, $view);
$harnessCtrl = new HarnessController($container, $view);
$postCtrl = new PostController($container, $view);
$prodCtrl = new ProductController($container, $view);
$taxCtrl = new TaxonomyController($container, $view);
$mediaCtrl = new MediaController($container, $view);
$orderCtrl = new OrderController($container, $view);
$commentCtrl = new CommentController($container, $view);
$settingCtrl = new SettingController($container, $view);

// Auth
$router->get('/admin/login', [$authCtrl, 'showLogin']);
$router->post('/admin/login', [$authCtrl, 'login']);
$router->post('/admin/logout', [$authCtrl, 'logout']);

// Dashboard & Modules Toggle Manager
$router->get('/admin', [$dashCtrl, 'index'], null, true);
$router->post('/admin/quick-draft', [$dashCtrl, 'quickDraft'], ModuleKey::POSTS, true);
$router->get('/admin/modules', [$modCtrl, 'index'], null, true);
$router->post('/admin/modules/toggle', [$modCtrl, 'toggle'], null, true);

// Tuzygent AI Autonomous Code Harness (Protected by ModuleKey::HARNESS)
$router->get('/admin/harness', [$harnessCtrl, 'index'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/create', [$harnessCtrl, 'createTask'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/run/{id}', [$harnessCtrl, 'runTask'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/verify', [$harnessCtrl, 'verify'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/snapshot', [$harnessCtrl, 'createSnapshot'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/rollback', [$harnessCtrl, 'rollback'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/delete/{id}', [$harnessCtrl, 'deleteTask'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/continuous/toggle', [$harnessCtrl, 'toggleContinuous'], ModuleKey::HARNESS, true);
$router->post('/admin/harness/continuous/tick', [$harnessCtrl, 'continuousTick'], ModuleKey::HARNESS, true);
$router->get('/admin/harness/continuous/status', [$harnessCtrl, 'continuousStatus'], ModuleKey::HARNESS, true);

// Posts Management
$router->get('/admin/posts', [$postCtrl, 'index'], ModuleKey::POSTS, true);
$router->get('/admin/posts/create', [$postCtrl, 'create'], ModuleKey::POSTS, true);
$router->get('/admin/posts/edit/{id}', [$postCtrl, 'edit'], ModuleKey::POSTS, true);
$router->post('/admin/posts/save', [$postCtrl, 'save'], ModuleKey::POSTS, true);
$router->post('/admin/posts/status/{id}', [$postCtrl, 'changeStatus'], ModuleKey::POSTS, true);
$router->post('/admin/posts/delete/{id}', [$postCtrl, 'delete'], ModuleKey::POSTS, true);

// Products Management
$router->get('/admin/products', [$prodCtrl, 'index'], ModuleKey::PRODUCTS, true);
$router->get('/admin/products/create', [$prodCtrl, 'create'], ModuleKey::PRODUCTS, true);
$router->get('/admin/products/edit/{id}', [$prodCtrl, 'edit'], ModuleKey::PRODUCTS, true);
$router->post('/admin/products/save', [$prodCtrl, 'save'], ModuleKey::PRODUCTS, true);
$router->post('/admin/products/status/{id}', [$prodCtrl, 'changeStatus'], ModuleKey::PRODUCTS, true);
$router->post('/admin/products/delete/{id}', [$prodCtrl, 'delete'], ModuleKey::PRODUCTS, true);

// Categories & Tags Management
$router->get('/admin/categories', [$taxCtrl, 'categories'], ModuleKey::CATEGORIES_TAGS, true);
$router->post('/admin/categories/save', [$taxCtrl, 'saveCategory'], ModuleKey::CATEGORIES_TAGS, true);
$router->post('/admin/categories/delete/{id}', [$taxCtrl, 'deleteCategory'], ModuleKey::CATEGORIES_TAGS, true);
$router->get('/admin/tags', [$taxCtrl, 'tags'], ModuleKey::CATEGORIES_TAGS, true);
$router->post('/admin/tags/save', [$taxCtrl, 'saveTag'], ModuleKey::CATEGORIES_TAGS, true);
$router->post('/admin/tags/delete/{id}', [$taxCtrl, 'deleteTag'], ModuleKey::CATEGORIES_TAGS, true);

// Media Library
$router->get('/admin/media', [$mediaCtrl, 'index'], ModuleKey::MEDIA, true);
$router->post('/admin/media/upload', [$mediaCtrl, 'upload'], ModuleKey::MEDIA, true);
$router->post('/admin/media/delete/{id}', [$mediaCtrl, 'delete'], ModuleKey::MEDIA, true);

// Orders Management
$router->get('/admin/orders', [$orderCtrl, 'index'], ModuleKey::ORDERS, true);
$router->get('/admin/orders/{id}', [$orderCtrl, 'detail'], ModuleKey::ORDERS, true);
$router->post('/admin/orders/status/{id}', [$orderCtrl, 'updateStatus'], ModuleKey::ORDERS, true);

// Comments & Reviews Management
$router->get('/admin/comments', [$commentCtrl, 'index'], ModuleKey::COMMENTS, true);
$router->post('/admin/comments/status/{id}', [$commentCtrl, 'changeStatus'], ModuleKey::COMMENTS, true);
$router->post('/admin/comments/delete/{id}', [$commentCtrl, 'delete'], ModuleKey::COMMENTS, true);

// Users & Settings
$router->get('/admin/users', [$settingCtrl, 'users'], null, true);
$router->post('/admin/users/save', [$settingCtrl, 'saveUser'], null, true);
$router->post('/admin/users/delete/{id}', [$settingCtrl, 'deleteUser'], null, true);
$router->get('/admin/settings', [$settingCtrl, 'settings'], null, true);
$router->post('/admin/settings/save', [$settingCtrl, 'saveSettings'], null, true);

// Dispatch incoming request
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
