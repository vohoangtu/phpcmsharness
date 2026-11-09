<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum HarnessTaskStatus: string
{
    case BACKLOG = 'backlog';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case READY_TO_INSTALL = 'ready_to_install';
    case FAILED = 'failed';
    case ROLLED_BACK = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::BACKLOG => 'Chờ thực thi (Backlog)',
            self::RUNNING => 'Đang chạy Loop',
            self::COMPLETED => 'Đã vượt Gate & Hoàn tất',
            self::READY_TO_INSTALL => 'Đạt Gate · Chờ cài đặt',
            self::FAILED => 'Tạo/cài cập nhật thất bại',
            self::ROLLED_BACK => 'Đã Rollback Snapshot',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::BACKLOG => 'badge-warning',
            self::RUNNING => 'badge-info',
            self::COMPLETED => 'badge-success',
            self::READY_TO_INSTALL => 'badge-info',
            self::FAILED => 'badge-danger',
            self::ROLLED_BACK => 'badge-danger',
        };
    }
}
