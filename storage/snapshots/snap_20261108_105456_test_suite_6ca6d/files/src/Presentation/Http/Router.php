<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Service\AuthService;
use App\Application\Service\ModuleManagerService;
use App\Domain\Enum\ModuleKey;
use App\Infrastructure\Container\Container;
use App\Presentation\View\ViewRenderer;

final class Router
{
    /**
     * @var array<int, array{method: string, pattern: string, handler: callable, module: ?ModuleKey, adminAuth: bool}>
     */
    private array $routes = [];

    public function __construct(
        private readonly Container $container,
        private readonly ViewRenderer $view
    ) {
    }

    public function get(string $pattern, callable $handler, ?ModuleKey $module = null, bool $adminAuth = false): void
    {
        $this->addRoute('GET', $pattern, $handler, $module, $adminAuth);
    }

    public function post(string $pattern, callable $handler, ?ModuleKey $module = null, bool $adminAuth = false): void
    {
        $this->addRoute('POST', $pattern, $handler, $module, $adminAuth);
    }

    private function addRoute(string $method, string $pattern, callable $handler, ?ModuleKey $module, bool $adminAuth): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'module' => $module,
            'adminAuth' => $adminAuth,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $auth = $this->container->get(AuthService::class);
        $moduleManager = $this->container->get(ModuleManagerService::class);

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $path, $matches)) {
                if ($route['method'] !== 'GET' && !Csrf::isValid($_POST['_csrf_token'] ?? null)) {
                    http_response_code(403);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'Invalid or missing CSRF token.';
                    return;
                }

                // 1. Check Admin Auth
                if ($route['adminAuth'] && !$auth->isAuthenticated()) {
                    header('Location: /admin/login');
                    return;
                }

                // 2. ModuleGuardMiddleware: Kiểm tra xem chức năng (Module) có đang được BẬT không
                if ($route['module'] !== null && !$moduleManager->isEnabled($route['module'])) {
                    if ($route['adminAuth']) {
                        $this->view->renderAdmin('admin/module_disabled', [
                            'pageTitle' => 'Chức năng đang tắt',
                            'disabledModule' => $route['module'],
                        ]);
                        return;
                    }
                    http_response_code(404);
                    $this->view->renderFrontend('frontend/404', [
                        'pageTitle' => 'Chức năng hiện đang tạm ngưng',
                        'message' => sprintf('Chức năng "%s" hiện đang được quản trị viên tạm tắt trên bảng điều khiển.', $route['module']->label()),
                    ]);
                    return;
                }

                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_int($key)) {
                        $params[$key] = $value;
                    }
                }

                ($route['handler'])(...array_values($params));
                return;
            }
        }

        http_response_code(404);
        $this->view->renderFrontend('frontend/404', [
            'pageTitle' => '404 - Không tìm thấy trang',
            'message' => 'Đường dẫn bạn truy cập không tồn tại hoặc đã bị di chuyển.',
        ]);
    }
}
