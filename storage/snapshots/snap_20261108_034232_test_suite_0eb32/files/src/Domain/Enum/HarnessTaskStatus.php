<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum HarnessTaskStatus: string
{
    case BACKLOG = 'backlog';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case ROLLED_BACK = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::BACKLOG => 'Chờ thực thi (Backlog)',
            self::RUNNING => 'Đang chạy Loop',
            self::COMPLETED => 'Đã vượt Gate & Hoàn tất',
            self::FAILED => 'Thất bại Gate',
            self::ROLLED_BACK => 'Đã Rollback Snapshot',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::BACKLOG => 'badge-warning',
            self::RUNNING => 'badge-info',
            self::COMPLETED => 'badge-success',
            self::FAILED => 'badge-danger',
            self::ROLLED_BACK => 'badge-danger',
        };
    }
}
