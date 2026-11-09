<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class SecurityHeaders
{
    /**
     * @return array<string, string>
     */
    public static function values(): array
    {
        return [
            'Content-Security-Policy-Report-Only' => "default-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
    }

    public static function send(): void
    {
        foreach (self::values() as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
