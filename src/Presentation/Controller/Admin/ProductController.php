<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\MediaService;
use App\Application\Service\ProductService;
use App\Application\Service\TaxonomyService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ProductSort;
use App\Presentation\Controller\BaseController;

final class ProductController extends BaseController
{
    public function index(): void
    {
        $productService = $this->container->get(ProductService::class);
        $taxonomyService = $this->container->get(TaxonomyService::class);

        $statusStr = (string) ($_GET['status'] ?? '');
        $status = $statusStr !== '' ? ContentStatus::tryFrom($statusStr) : null;
        $stockFilter = in_array($_GET['stock'] ?? null, ['in_stock', 'out_of_stock'], true)
            ? (string) $_GET['stock']
            : '';
        $inStock = $stockFilter === '' ? null : $stockFilter === 'in_stock';
        $categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
        $search = trim((string) ($_GET['s'] ?? ''));
        $pageSize = 20;
        $totalItems = $productService->countProducts($status, $categoryId, $search, null, null, null, $inStock);
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $currentPage = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);

        $this->view->renderAdmin('admin/products/index', [
            'pageTitle' => 'Quản lý Sản phẩm (Products)',
            'products' => $productService->listProducts($status, $categoryId, $search, null, $pageSize, ($currentPage - 1) * $pageSize, ProductSort::NEWEST, null, null, $inStock),
            'counts' => $productService->getStatusCounts(),
            'categories' => $taxonomyService->getCategories('product'),
            'currentStatus' => $statusStr,
            'stockFilter' => $stockFilter,
            'currentCategory' => $categoryId,
            'search' => $search,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'pageSize' => $pageSize,
        ]);
    }

    public function create(): void
    {
        $this->renderForm(null);
    }

    public function edit(string $id): void
    {
        $product = $this->container->get(ProductService::class)->getById((int) $id);
        if ($product === null) {
            $this->flash('error', 'Không tìm thấy sản phẩm.');
            $this->redirect('/admin/products');
            return;
        }
        $this->renderForm($product);
    }

    public function save(): void
    {
        $productService = $this->container->get(ProductService::class);
        $mediaService = $this->container->get(MediaService::class);

        $data = $_POST;
        if (!empty($_FILES['featured_image_file']['tmp_name'])) {
            try {
                $uploaded = $mediaService->upload($_FILES['featured_image_file'], (string) ($data['name'] ?? ''));
                $data['featured_image'] = $uploaded->filePath;
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage());
            }
        }

        $savedId = $productService->saveProduct($data);
        $this->flash('success', 'Đã lưu thông tin sản phẩm thành công!');
        $this->redirect('/admin/products/edit/' . $savedId);
    }

    public function uploadImage(): void
    {
        try {
            $uploaded = $this->container->get(MediaService::class)->upload(
                $_FILES['file'] ?? [],
                trim((string)($_POST['alt_text'] ?? ''))
            );
            $this->json(['ok'=>true,'item'=>[
                'id'=>$uploaded->id,
                'url'=>$uploaded->filePath,
                'alt'=>$uploaded->altText,
                'name'=>$uploaded->originalName,
                'width'=>$uploaded->width,
                'height'=>$uploaded->height,
            ]]);
        } catch (\Throwable $e) {
            $this->json(['ok'=>false,'error'=>$e->getMessage()], 422);
        }
    }

    public function mediaLibrary(): void
    {
        $mediaService = $this->container->get(MediaService::class);
        $pageSize = 36;
        $search = trim((string) ($_GET['q'] ?? ''));
        if ($search !== '') {
            $lower = static fn(string $value): string => function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
            $needle = $lower($search);
            $allItems = array_values(array_filter($mediaService->listAll(), static fn($file): bool =>
                str_contains($lower($file->originalName), $needle)
                || str_contains($lower($file->filePath), $needle)
                || str_contains($lower($file->altText), $needle)
            ));
            $totalItems = count($allItems);
        } else {
            $allItems = null;
            $totalItems = $mediaService->countAll();
        }
        $totalPages = max(1, (int)ceil($totalItems / $pageSize));
        $page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
        $files = $allItems === null
            ? $mediaService->listPage($pageSize, ($page - 1) * $pageSize)
            : array_slice($allItems, ($page - 1) * $pageSize, $pageSize);
        $items = array_map(static fn($file): array => [
            'id'=>$file->id,
            'url'=>$file->filePath,
            'alt'=>$file->altText,
            'name'=>$file->originalName,
            'width'=>$file->width,
            'height'=>$file->height,
        ], $files);
        $this->json(['items'=>$items,'page'=>$page,'total_pages'=>$totalPages,'total'=>$totalItems]);
    }

    public function changeStatus(string $id): void
    {
        $statusStr = (string) ($_POST['status'] ?? 'trash');
        $status = ContentStatus::tryFrom($statusStr) ?? ContentStatus::TRASH;
        $this->container->get(ProductService::class)->changeStatus((int) $id, $status);
        $this->flash('success', 'Đã cập nhật trạng thái sản phẩm.');
        $this->redirect('/admin/products');
    }

    public function delete(string $id): void
    {
        $this->container->get(ProductService::class)->deletePermanently((int) $id);
        $this->flash('success', 'Đã xóa vĩnh viễn sản phẩm.');
        $this->redirect('/admin/products?status=trash');
    }

    private function renderForm(mixed $product): void
    {
        $taxonomyService = $this->container->get(TaxonomyService::class);

        $this->view->renderAdmin('admin/products/form', [
            'pageTitle' => $product ? 'Chỉnh sửa Sản phẩm' : 'Thêm Sản phẩm mới',
            'product' => $product,
            'categories' => $taxonomyService->getCategories('product'),
        ]);
    }
}
