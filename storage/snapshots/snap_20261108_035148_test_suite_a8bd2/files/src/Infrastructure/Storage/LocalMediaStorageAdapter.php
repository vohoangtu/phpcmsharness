<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Entity\MediaFile;
use App\Domain\Port\Outbound\MediaStoragePort;
use RuntimeException;

final readonly class LocalMediaStorageAdapter implements MediaStoragePort
{
    public function __construct(
        private string $uploadsDir,
        private string $uploadsUrlPrefix = '/uploads'
    ) {
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0775, true);
        }
    }

    public function storeUploadedFile(array $uploadedFile, string $altText = ''): MediaFile
    {
        if (empty($uploadedFile['tmp_name']) || (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Tệp tải lên không hợp lệ hoặc bị lỗi.');
        }

        $originalName = (string) ($uploadedFile['name'] ?? 'file');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (!in_array($ext, $allowedExtensions, true)) {
            throw new RuntimeException('Chỉ hỗ trợ định dạng ảnh: JPG, PNG, GIF, WEBP, SVG.');
        }

        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = rtrim($this->uploadsDir, '/') . '/' . $filename;

        $tmpName = (string) $uploadedFile['tmp_name'];
        if (is_uploaded_file($tmpName)) {
            move_uploaded_file($tmpName, $destination);
        } else {
            copy($tmpName, $destination);
        }

        $fileSize = file_exists($destination) ? (int) filesize($destination) : (int) ($uploadedFile['size'] ?? 0);
        $mimeType = (string) ($uploadedFile['type'] ?? 'image/' . $ext);
        $publicPath = rtrim($this->uploadsUrlPrefix, '/') . '/' . $filename;

        return new MediaFile(
            id: null,
            filename: $filename,
            originalName: $originalName,
            filePath: $publicPath,
            mimeType: $mimeType,
            fileSize: $fileSize,
            altText: trim($altText) !== '' ? trim($altText) : pathinfo($originalName, PATHINFO_FILENAME)
        );
    }

    public function deletePhysicalFile(string $filename): void
    {
        $path = rtrim($this->uploadsDir, '/') . '/' . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
