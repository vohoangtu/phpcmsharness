<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Admin;

use App\Application\Service\MediaService;
use App\Presentation\Controller\BaseController;

final class MediaController extends BaseController
{
    public function index(): void
    {
        $mediaService = $this->container->get(MediaService::class);
        $this->view->renderAdmin('admin/media/index', [
            'pageTitle' => 'Thư viện Media',
            'mediaFiles' => $mediaService->listAll(),
        ]);
    }

    public function upload(): void
    {
        $mediaService = $this->container->get(MediaService::class);
        if (!empty($_FILES['file']['tmp_name'])) {
            try {
                $altText = trim((string) ($_POST['alt_text'] ?? ''));
                $mediaService->upload($_FILES['file'], $altText);
                $this->flash('success', 'Tải tệp lên Thư viện Media thành công!');
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage());
            }
        } else {
            $this->flash('error', 'Vui lòng chọn một tệp hình ảnh hợp lệ.');
        }

        $this->redirect('/admin/media');
    }

    public function delete(string $id): void
    {
        $this->container->get(MediaService::class)->delete((int) $id);
        $this->flash('success', 'Đã xóa tệp khỏi Thư viện Media.');
        $this->redirect('/admin/media');
    }
}
