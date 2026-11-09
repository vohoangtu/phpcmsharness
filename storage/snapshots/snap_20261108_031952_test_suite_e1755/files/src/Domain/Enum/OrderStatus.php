<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Chờ xử lý',
            self::PROCESSING => 'Đang giao hàng',
            self::COMPLETED => 'Hoàn thành',
            self::CANCELLED => 'Đã hủy',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning',
            self::PROCESSING => 'badge-info',
            self::COMPLETED => 'badge-success',
            self::CANCELLED => 'badge-danger',
        };
    }
}
