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
        $postService = $postsEnabled ? $this->container->get(PostService::class) : null;
        $productService = $productsEnabled ? $this->container->get(ProductService::class) : null;
        $postCount = $postService?->countPosts(ContentStatus::PUBLISHED) ?? 0;
        $productCount = $productService?->countProducts(ContentStatus::PUBLISHED) ?? 0;
        $staticCount = 1 + (int) $postsEnabled + (int) $productsEnabled;
        $contentCount = $postCount + $productCount;
        $builder = new SitemapXmlBuilder($this->siteUrl);

        $rawPart = $_GET['part'] ?? null;
        $part = null;
        if ($rawPart !== null) {
            $parsedPart = is_string($rawPart) || is_int($rawPart)
                ? filter_var($rawPart, FILTER_VALIDATE_INT)
                : false;
            if ($parsedPart === false || $parsedPart < 0) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Sitemap part not found.';
                return;
            }
            $part = $parsedPart;
        }

        if ($staticCount + $contentCount > SitemapXmlBuilder::MAX_URLS) {
            $pageCapacity = SitemapXmlBuilder::MAX_URLS - $staticCount;
            $pageCount = (int) ceil($contentCount / $pageCapacity);
            if ($part === null) {
                $sitemapUrls = [];
                for ($index = 0; $index < $pageCount; $index++) {
                    $sitemapUrls[] = $builder->sitemapUrlForPart($index);
                }
                http_response_code(200);
                header('Content-Type: application/xml; charset=utf-8');
                echo $builder->buildIndex($sitemapUrls);
                return;
            }
            if ($part >= $pageCount) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Sitemap part not found.';
                return;
            }

            $offset = $part * $pageCapacity;
            $posts = [];
            $products = [];
            if ($offset < $postCount) {
                $postLimit = min($pageCapacity, $postCount - $offset);
                $posts = $postService?->listPosts(ContentStatus::PUBLISHED, null, '', $postLimit, $offset) ?? [];
            }
            $productOffset = max(0, $offset - $postCount);
            $remaining = $pageCapacity - count($posts);
            if ($remaining > 0 && $productOffset < $productCount) {
                $products = $productService?->listProducts(
                    ContentStatus::PUBLISHED,
                    null,
                    '',
                    null,
                    min($remaining, $productCount - $productOffset),
                    $productOffset,
                    ProductSort::NEWEST
                ) ?? [];
            }

            http_response_code(200);
            header('Content-Type: application/xml; charset=utf-8');
            echo $builder->build($posts, $products, $part === 0 && $postsEnabled, $part === 0 && $productsEnabled);
            return;
        }

        if ($part !== null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Sitemap part not found.';
            return;
        }

        $posts = $postService?->listPosts(ContentStatus::PUBLISHED, null, '', SitemapXmlBuilder::MAX_URLS) ?? [];
        $products = $productService?->listProducts(
            ContentStatus::PUBLISHED,
            null,
            '',
            null,
            SitemapXmlBuilder::MAX_URLS,
            0,
            ProductSort::NEWEST
        ) ?? [];

        http_response_code(200);
        header('Content-Type: application/xml; charset=utf-8');
        echo $builder->build($posts, $products, $postsEnabled, $productsEnabled);
    }
}
