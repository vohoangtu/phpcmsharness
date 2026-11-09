<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum CommentStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case SPAM = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Chờ duyệt',
            self::APPROVED => 'Đã duyệt',
            self::SPAM => 'Spam',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning',
            self::APPROVED => 'badge-success',
            self::SPAM => 'badge-danger',
        };
    }
}
