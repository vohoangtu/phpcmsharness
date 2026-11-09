<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\ModuleManagerService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ModuleKey;
use App\Domain\Enum\ProductSort;
use App\Presentation\Controller\BaseController;

final class SearchController extends BaseController
{
    private const PAGE_SIZE = 6;
    private const MAX_QUERY_LENGTH = 200;

    public function index(): void
    {
        $rawSearch = $_GET['s'] ?? '';
        $search = is_string($rawSearch)
            ? mb_substr(trim($rawSearch), 0, self::MAX_QUERY_LENGTH)
            : '';

        $moduleManager = $this->container->get(ModuleManagerService::class);
        $postsEnabled = $moduleManager->isEnabled(ModuleKey::POSTS);
        $productsEnabled = $moduleManager->isEnabled(ModuleKey::PRODUCTS);

        $postPage = $this->requestedPage($_GET['posts_page'] ?? 1);
        $productPage = $this->requestedPage($_GET['products_page'] ?? 1);
        $posts = [];
        $products = [];
        $postTotalPages = 1;
        $productTotalPages = 1;

        if ($search !== '' && $postsEnabled) {
            $postService = $this->container->get(PostService::class);
            $postCount = $postService->countPosts(ContentStatus::PUBLISHED, null, $search);
            $postTotalPages = max(1, (int) ceil($postCount / self::PAGE_SIZE));
            $postPage = min($postPage, $postTotalPages);
            $posts = $postService->listPosts(
                ContentStatus::PUBLISHED,
                null,
                $search,
                self::PAGE_SIZE,
                ($postPage - 1) * self::PAGE_SIZE
            );
        }

        if ($search !== '' && $productsEnabled) {
            $productService = $this->container->get(ProductService::class);
            $productCount = $productService->countProducts(ContentStatus::PUBLISHED, null, $search);
            $productTotalPages = max(1, (int) ceil($productCount / self::PAGE_SIZE));
            $productPage = min($productPage, $productTotalPages);
            $products = $productService->listProducts(
                ContentStatus::PUBLISHED,
                null,
                $search,
                null,
                self::PAGE_SIZE,
                ($productPage - 1) * self::PAGE_SIZE,
                ProductSort::NEWEST
            );
        }

        $this->view->renderFrontend('frontend/search', [
            'pageTitle' => $search === '' ? 'Tìm kiếm' : 'Kết quả tìm kiếm: ' . $search,
            'search' => $search,
            'posts' => $posts,
            'products' => $products,
            'postsEnabled' => $postsEnabled,
            'productsEnabled' => $productsEnabled,
            'postPage' => $postPage,
            'postTotalPages' => $postTotalPages,
            'productPage' => $productPage,
            'productTotalPages' => $productTotalPages,
        ]);
    }

    private function requestedPage(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT);
        return $page === false ? 1 : max(1, $page);
    }
}
