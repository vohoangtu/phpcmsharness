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
        $pageSize = 24;
        $query = trim((string) ($_GET['q'] ?? ''));
        $type = (string) ($_GET['type'] ?? 'all');
        $sort = (string) ($_GET['sort'] ?? 'newest');
        $folderId = ($_GET['folder'] ?? '') === '' ? null : (int)$_GET['folder'];
        $files = $mediaService->listAll();
        if ($folderId !== null) $files = array_values(array_filter($files, static fn($file): bool => $file->folderId === $folderId));
        if ($query !== '') {
            $files = array_values(array_filter($files, static fn($file): bool => stripos($file->originalName . ' ' . $file->altText . ' ' . $file->tags . ' ' . $file->filePath, $query) !== false));
        }
        if (in_array($type, ['jpeg', 'png', 'gif', 'webp'], true)) {
            $files = array_values(array_filter($files, static fn($file): bool => str_contains($file->mimeType, $type === 'jpeg' ? 'jpeg' : $type)));
        }
        usort($files, static fn($a, $b): int => match ($sort) {
            'name' => strcasecmp($a->originalName, $b->originalName),
            'largest' => $b->fileSize <=> $a->fileSize,
            'oldest' => strcmp($a->createdAt, $b->createdAt),
            default => $b->id <=> $a->id,
        });
        $totalItems = count($files);
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $currentPage = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);

        $this->view->renderAdmin('admin/media/index', [
            'pageTitle' => 'Thư viện Media',
            'mediaFiles' => array_slice($files, ($currentPage - 1) * $pageSize, $pageSize),
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'pageSize' => $pageSize,
            'query' => $query,
            'typeFilter' => $type,
            'sortOrder' => $sort,
            'folders' => $mediaService->folders(),
            'currentFolder' => $folderId,
            'recentFiles' => array_slice($mediaService->listAll(), 0, 6),
            'recentlyUsedFiles' => $mediaService->recentlyUsed(),
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

    public function uploadJson(): void
    {
        try {
            $file = $this->container->get(MediaService::class)->upload(
                $_FILES['file'] ?? [],
                trim((string) ($_POST['alt_text'] ?? ''))
            );
            $this->json(['ok' => true, 'item' => [
                'id' => $file->id,
                'url' => $file->filePath,
                'alt' => $file->altText,
                'name' => $file->originalName,
                'width' => $file->width,
                'height' => $file->height,
            ]]);
        } catch (\Throwable $error) {
            $this->json(['ok' => false, 'error' => $error->getMessage()], 422);
        }
    }

    public function libraryJson(): void
    {
        $mediaService = $this->container->get(MediaService::class);
        $pageSize = 36;
        $query = trim((string) ($_GET['q'] ?? ''));
        $files = $query === '' ? null : array_values(array_filter(
            $mediaService->listAll(),
            static fn($file): bool => stripos($file->originalName . ' ' . $file->altText . ' ' . $file->tags . ' ' . $file->filePath, $query) !== false
        ));
        $total = $files === null ? $mediaService->countAll() : count($files);
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
        $items = $files === null
            ? $mediaService->listPage($pageSize, ($page - 1) * $pageSize)
            : array_slice($files, ($page - 1) * $pageSize, $pageSize);
        $this->json(['items' => array_map(static fn($file): array => [
            'id' => $file->id,
            'url' => $file->filePath,
            'alt' => $file->altText,
            'name' => $file->originalName,
            'width' => $file->width,
            'height' => $file->height,
        ], $items), 'page' => $page, 'total_pages' => $pages, 'total' => $total]);
    }

    public function delete(string $id): void
    {
        $this->container->get(MediaService::class)->delete((int) $id);
        $this->flash('success', 'Đã xóa tệp khỏi Thư viện Media.');
        $this->redirect('/admin/media');
    }

    public function deleteBulk(): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['media_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        foreach ($ids as $id) {
            $this->container->get(MediaService::class)->delete($id);
        }
        $this->flash('success', sprintf('Đã xóa %d ảnh khỏi thư viện.', count($ids)));
        $this->redirect('/admin/media');
    }

    public function createFolder(): void
    {
        try {
            $this->container->get(MediaService::class)->createFolder((string)($_POST['name'] ?? ''));
            $this->flash('success', 'Đã tạo thư mục Media.');
        } catch (\Throwable $error) {
            $this->flash('error', $error->getMessage());
        }
        $this->redirect('/admin/media');
    }

    public function moveBulk(): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['media_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        $folder = (string)($_POST['folder_id'] ?? '') === '' ? null : (int)$_POST['folder_id'];
        $this->container->get(MediaService::class)->moveToFolder($ids, $folder);
        $this->flash('success', sprintf('Đã chuyển %d ảnh.', count($ids)));
        $this->redirect('/admin/media');
    }

    public function updateMetadata(string $id): void
    {
        $this->container->get(MediaService::class)->updateMetadata((int)$id, (string)($_POST['alt_text'] ?? ''), (string)($_POST['tags'] ?? ''));
        $this->flash('success', 'Đã cập nhật thông tin ảnh.');
        $this->redirect('/admin/media');
    }

    public function markUsedJson(): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['media_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        $this->container->get(MediaService::class)->markUsed($ids);
        $this->json(['ok' => true]);
    }
}
