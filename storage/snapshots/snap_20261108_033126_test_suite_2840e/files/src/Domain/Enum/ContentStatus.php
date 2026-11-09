<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum ContentStatus: string
{
    case PUBLISHED = 'published';
    case DRAFT = 'draft';
    case TRASH = 'trash';

    public function label(): string
    {
        return match ($this) {
            self::PUBLISHED => 'Đã xuất bản',
            self::DRAFT => 'Bản nháp',
            self::TRASH => 'Thùng rác',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PUBLISHED => 'badge-success',
            self::DRAFT => 'badge-warning',
            self::TRASH => 'badge-danger',
        };
    }
}
