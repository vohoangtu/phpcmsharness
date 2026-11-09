<?php

declare(strict_types=1);

namespace App\Application\Service;

use InvalidArgumentException;

final readonly class RobotsTxtBuilder
{
    public function __construct(private string $siteUrl)
    {
    }

    public function build(): string
    {
        return "User-agent: *\n"
            . "Disallow: /admin\n"
            . 'Sitemap: ' . $this->sitemapUrl() . "\n";
    }

    private function sitemapUrl(): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $this->siteUrl) === 1) {
            throw new InvalidArgumentException('Robots.txt site URL must not contain control characters.');
        }

        $parts = parse_url(trim($this->siteUrl));
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Robots.txt site URL must be an absolute HTTP(S) URL without credentials, query, or fragment.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme . '://' . $host . $port . $path . '/sitemap.xml';
    }
}
