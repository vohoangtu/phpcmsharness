<?php

declare(strict_types=1);

namespace App\Presentation\Http;

final class LocalRedirect
{
    public static function resolve(string $candidate, string $fallback = '/cart'): string
    {
        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate) === 1) {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)
            || isset($parts['scheme'], $parts['host'])
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')
        ) {
            return $fallback;
        }

        return $candidate;
    }
}
