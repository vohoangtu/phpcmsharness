<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\ModuleManagerService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Presentation\Controller\BaseController;

final class HomeController extends BaseController
{
    public function index(): void
    {
        $moduleManager = $this->container->get(ModuleManagerService::class);
        $postService = $this->container->get(PostService::class);
        $productService = $this->container->get(ProductService::class);

        $featuredProducts = $moduleManager->isEnabled(ModuleKey::PRODUCTS)
            ? $productService->listProducts(ContentStatus::PUBLISHED, null, '', null, 6)
            : [];

        $latestPosts = $moduleManager->isEnabled(ModuleKey::POSTS)
            ? $postService->listPosts(ContentStatus::PUBLISHED, null, '', 4)
            : [];

        $settings = $this->container->get(SettingRepositoryPort::class)->getAll();
        $homeSlider = json_decode((string)($settings['home_slider_config'] ?? ''), true);
        if (!is_array($homeSlider)) $homeSlider = [];
        $homeSlider['slides'] = array_values(array_filter(
            is_array($homeSlider['slides'] ?? null) ? $homeSlider['slides'] : [],
            static fn($slide): bool => is_array($slide) && !empty($slide['enabled']) && is_string($slide['image'] ?? null) && trim($slide['image']) !== ''
        ));
        $homeSlider['enabled'] = !empty($homeSlider['enabled']) && $homeSlider['slides'] !== [];

        $this->view->renderFrontend('frontend/home', [
            'pageTitle' => 'Trang chủ',
            'featuredProducts' => $featuredProducts,
            'latestPosts' => $latestPosts,
            'homeSlider' => $homeSlider,
        ]);
    }
}
