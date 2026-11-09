<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\CommentService;
use App\Application\Service\ModuleManagerService;
use App\Application\Service\ProductService;
use App\Application\Service\TaxonomyService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\SettingRepositoryPort;
use App\Presentation\Controller\BaseController;

final class ShopController extends BaseController
{
    public function index(): void
    {
        $productService = $this->container->get(ProductService::class);
        $taxonomyService = $this->container->get(TaxonomyService::class);

        $categoryId = !empty($_GET['category']) ? (int) $_GET['category'] : null;
        $search = trim((string) ($_GET['s'] ?? ''));
        $pageSize = 12;
        $totalProducts = $productService->countProducts(ContentStatus::PUBLISHED, $categoryId, $search);
        $totalPages = max(1, (int) ceil($totalProducts / $pageSize));
        $requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
        $currentPage = max(1, min($totalPages, $requestedPage === false ? 1 : $requestedPage));

        $this->view->renderFrontend('frontend/shop/index', [
            'pageTitle' => 'Cửa hàng Sản phẩm',
            'products' => $productService->listProducts(ContentStatus::PUBLISHED, $categoryId, $search, null, $pageSize, ($currentPage - 1) * $pageSize),
            'categories' => $taxonomyService->getCategories('product'),
            'currentCategory' => $categoryId,
            'search' => $search,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);
    }

    public function show(string $slug): void
    {
        $productService = $this->container->get(ProductService::class);
        $commentService = $this->container->get(CommentService::class);
        $moduleManager = $this->container->get(ModuleManagerService::class);

        $product = $productService->getPublishedBySlug($slug);
        if ($product === null) {
            http_response_code(404);
            $this->view->renderFrontend('frontend/404', [
                'pageTitle' => 'Không tìm thấy sản phẩm',
                'message' => 'Sản phẩm bạn tìm kiếm không tồn tại hoặc đã ngừng kinh doanh.',
            ]);
            return;
        }

        $reviews = $moduleManager->isEnabled(ModuleKey::COMMENTS)
            ? $commentService->getApprovedComments('product', (int) $product->id)
            : [];

        $this->view->renderFrontend('frontend/shop/show', [
            'pageTitle' => $product->name,
            'product' => $product,
            'reviews' => $reviews,
            'adminEditUrl' => '/admin/products/edit/' . $product->id,
        ]);
    }

    public function addReview(string $slug): void
    {
        $product = $this->container->get(ProductService::class)->getPublishedBySlug($slug);
        if ($product === null) {
            $this->redirect('/shop');
            return;
        }

        $autoApprove = $this->container->get(SettingRepositoryPort::class)->get('auto_approve_comments', '1') === '1';
        $this->container->get(CommentService::class)->addComment('product', (int) $product->id, $_POST, $autoApprove);

        $this->flash('success', $autoApprove ? 'Cảm ơn bạn đã gửi đánh giá sản phẩm!' : 'Đánh giá của bạn đã được gửi và đang chờ duyệt.');
        $this->redirect('/product/' . $product->slug . '#reviews');
    }
}
