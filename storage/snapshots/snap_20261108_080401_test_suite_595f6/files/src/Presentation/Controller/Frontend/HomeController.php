<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\ModuleManagerService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ModuleKey;
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

        $this->view->renderFrontend('frontend/home', [
            'pageTitle' => 'Trang chủ',
            'featuredProducts' => $featuredProducts,
            'latestPosts' => $latestPosts,
        ]);
    }
}
