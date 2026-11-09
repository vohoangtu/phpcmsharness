<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum ProductSort: string
{
    case NEWEST = 'newest';
    case NAME_ASC = 'name_asc';
    case PRICE_ASC = 'price_asc';
    case PRICE_DESC = 'price_desc';

    public function label(): string
    {
        return match ($this) {
            self::NEWEST => 'Mới nhất',
            self::NAME_ASC => 'Tên A–Z',
            self::PRICE_ASC => 'Giá thấp đến cao',
            self::PRICE_DESC => 'Giá cao đến thấp',
        };
    }
}
