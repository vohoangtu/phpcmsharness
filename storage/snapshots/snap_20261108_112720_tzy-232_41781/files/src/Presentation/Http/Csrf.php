<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function isValid(mixed $submitted): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected)
            && is_string($submitted)
            && $expected !== ''
            && hash_equals($expected, $submitted);
    }
}
