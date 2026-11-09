<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\ModuleManagerService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Application\Service\SitemapXmlBuilder;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ModuleKey;
use App\Domain\Enum\ProductSort;
use App\Infrastructure\Container\Container;
use App\Presentation\Controller\BaseController;
use App\Presentation\View\ViewRenderer;

final class SitemapController extends BaseController
{
    public function __construct(
        Container $container,
        ViewRenderer $view,
        private readonly string $siteUrl
    ) {
        parent::__construct($container, $view);
    }

    public function index(): void
    {
        $modules = $this->container->get(ModuleManagerService::class);
        $postsEnabled = $modules->isEnabled(ModuleKey::POSTS);
        $productsEnabled = $modules->isEnabled(ModuleKey::PRODUCTS);
        $posts = $postsEnabled
            ? $this->container->get(PostService::class)->listPosts(ContentStatus::PUBLISHED, null, '', SitemapXmlBuilder::MAX_URLS)
            : [];
        $products = $productsEnabled
            ? $this->container->get(ProductService::class)->listProducts(
                ContentStatus::PUBLISHED,
                null,
                '',
                null,
                SitemapXmlBuilder::MAX_URLS,
                0,
                ProductSort::NEWEST
            )
            : [];

        http_response_code(200);
        header('Content-Type: application/xml; charset=utf-8');
        echo (new SitemapXmlBuilder($this->siteUrl))->build($posts, $products, $postsEnabled, $productsEnabled);
    }
}
