<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\TaxonomyService;
use App\Presentation\Controller\BaseController;

final class TaxonomyController extends BaseController
{
    public function categories(): void
    {
        $type = in_array(($_GET['type'] ?? 'post'), ['post', 'product'], true) ? (string) $_GET['type'] : 'post';
        $editId = !empty($_GET['edit']) ? (int) $_GET['edit'] : null;

        $service = $this->container->get(TaxonomyService::class);
        $editingCategory = $editId ? $service->getCategoryById($editId) : null;

        $this->view->renderAdmin('admin/taxonomies/categories', [
            'pageTitle' => $type === 'product' ? 'Danh mục Sản phẩm' : 'Danh mục Bài viết',
            'type' => $type,
            'categories' => $service->getCategories($type),
            'editingCategory' => $editingCategory,
        ]);
    }

    public function saveCategory(): void
    {
        $type = (string) ($_POST['type'] ?? 'post');
        $this->container->get(TaxonomyService::class)->saveCategory($_POST);
        $this->flash('success', 'Đã lưu danh mục thành công!');
        $this->redirect('/admin/categories?type=' . urlencode($type));
    }

    public function deleteCategory(string $id): void
    {
        $type = (string) ($_POST['type'] ?? 'post');
        $this->container->get(TaxonomyService::class)->deleteCategory((int) $id);
        $this->flash('success', 'Đã xóa danh mục.');
        $this->redirect('/admin/categories?type=' . urlencode($type));
    }

    public function tags(): void
    {
        $service = $this->container->get(TaxonomyService::class);
        $this->view->renderAdmin('admin/taxonomies/tags', [
            'pageTitle' => 'Quản lý Thẻ (Tags)',
            'tags' => $service->getAllTags(),
        ]);
    }

    public function saveTag(): void
    {
        $this->container->get(TaxonomyService::class)->saveTag($_POST);
        $this->flash('success', 'Đã lưu thẻ (Tag) thành công!');
        $this->redirect('/admin/tags');
    }

    public function deleteTag(string $id): void
    {
        $this->container->get(TaxonomyService::class)->deleteTag((int) $id);
        $this->flash('success', 'Đã xóa thẻ (Tag).');
        $this->redirect('/admin/tags');
    }
}
