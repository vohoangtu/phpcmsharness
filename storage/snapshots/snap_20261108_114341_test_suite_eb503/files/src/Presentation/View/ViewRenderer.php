<?php

declare(strict_types=1);

namespace App\Presentation\View;

use App\Application\Service\AuthService;
use App\Application\Service\ModuleManagerService;
use App\Domain\Port\Outbound\CartPort;
use App\Domain\Port\Outbound\CommentRepositoryPort;
use App\Domain\Port\Outbound\OrderRepositoryPort;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Infrastructure\Container\Container;

final readonly class ViewRenderer
{
    private string $viewsPath;

    public function __construct(
        private Container $container
    ) {
        $this->viewsPath = dirname(__DIR__) . '/Views';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderAdmin(string $template, array $data = []): void
    {
        $shared = $this->getSharedData();
        $viewData = array_merge($shared, $data);
        extract($viewData, EXTR_SKIP);

        ob_start();
        require $this->viewsPath . '/' . ltrim($template, '/') . '.php';
        $content = (string) ob_get_clean();

        require $this->viewsPath . '/layouts/admin.php';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderFrontend(string $template, array $data = []): void
    {
        $shared = $this->getSharedData();
        $viewData = array_merge($shared, $data);
        extract($viewData, EXTR_SKIP);

        ob_start();
        require $this->viewsPath . '/' . ltrim($template, '/') . '.php';
        $content = (string) ob_get_clean();

        require $this->viewsPath . '/layouts/frontend.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function getSharedData(): array
    {
        $moduleManager = $this->container->get(ModuleManagerService::class);
        $settings = $this->container->get(SettingRepositoryPort::class)->getAll();
        $auth = $this->container->get(AuthService::class);
        $cart = $this->container->get(CartPort::class);
        $commentCounts = $this->container->get(CommentRepositoryPort::class)->countByStatuses();
        $orderStats = $this->container->get(OrderRepositoryPort::class)->getSummaryStats();

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return [
            'moduleManager' => $moduleManager,
            'enabledModules' => $moduleManager->getEnabledMap(),
            'settings' => $settings,
            'currentUser' => $auth->currentUser(),
            'cartCount' => $cart->totalCount(),
            'pendingCommentsCount' => $commentCounts['pending'] ?? 0,
            'pendingOrdersCount' => $orderStats['pending_orders'] ?? 0,
            'flash' => $flash,
            'currentUri' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        ];
    }
}
