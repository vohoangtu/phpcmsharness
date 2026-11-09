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
        $allowedMimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        if (!isset($allowedMimeTypes[$ext])) {
            throw new RuntimeException('Chỉ hỗ trợ ảnh raster định dạng JPG, PNG, GIF hoặc WEBP.');
        }

        $tmpName = (string) $uploadedFile['tmp_name'];
        if (!is_file($tmpName) || !is_readable($tmpName)) {
            throw new RuntimeException('Không thể đọc nội dung tệp tải lên.');
        }

        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!is_string($detectedMimeType) || $detectedMimeType !== $allowedMimeTypes[$ext]) {
            throw new RuntimeException('Nội dung tệp không khớp với định dạng ảnh được khai báo.');
        }

        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = rtrim($this->uploadsDir, '/') . '/' . $filename;

        $stored = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $destination)
            : copy($tmpName, $destination);
        if (!$stored) {
            throw new RuntimeException('Không thể lưu tệp tải lên.');
        }

        $fileSize = (int) filesize($destination);
        $publicPath = rtrim($this->uploadsUrlPrefix, '/') . '/' . $filename;

        return new MediaFile(
            id: null,
            filename: $filename,
            originalName: $originalName,
            filePath: $publicPath,
            mimeType: $detectedMimeType,
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
