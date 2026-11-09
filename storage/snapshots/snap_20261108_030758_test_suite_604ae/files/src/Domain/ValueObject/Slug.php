<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final readonly class Slug
{
    public string $value;

    public function __construct(string $rawText)
    {
        $this->value = self::slugify($rawText);
    }

    public static function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $vietnameseMap = [
            '/[àáạảãâầấậẩẫăằắặẳẵ]/u' => 'a',
            '/[èéẹẻẽêềếệểễ]/u' => 'e',
            '/[ìíịỉĩ]/u' => 'i',
            '/[òóọỏõôồốộổỗơờớợởỡ]/u' => 'o',
            '/[ùúụủũưừứựửữ]/u' => 'u',
            '/[ỳýỵỷỹ]/u' => 'y',
            '/[đ]/u' => 'd',
        ];
        foreach ($vietnameseMap as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }
        $text = (string) preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = (string) preg_replace('/[\s-]+/', '-', $text);
        $slug = trim($text, '-');

        return $slug !== '' ? $slug : 'item-' . substr(md5((string) microtime(true)), 0, 6);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
