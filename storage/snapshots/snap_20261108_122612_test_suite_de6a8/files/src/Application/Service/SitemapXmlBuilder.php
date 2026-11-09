<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\Post;
use App\Domain\Entity\Product;
use App\Domain\Enum\ContentStatus;
use DateTimeImmutable;
use Throwable;

final readonly class SitemapXmlBuilder
{
    public const MAX_URLS = 20000;
    private const MAX_URL_LENGTH = 2048;
    private const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(private string $siteUrl)
    {
    }

    /**
     * @param Post[] $posts Published posts only
     * @param Product[] $products Published products only
     */
    public function build(array $posts, array $products, bool $includeBlog, bool $includeShop): string
    {
        $base = $this->normalizedBaseUrl();
        $entries = [];
        $this->appendEntry($entries, $base . '/', '');
        if ($includeBlog) {
            $this->appendEntry($entries, $base . '/blog', '');
        }
        if ($includeShop) {
            $this->appendEntry($entries, $base . '/shop', '');
        }

        foreach ($posts as $post) {
            if (!$post->isPublished() || count($entries) >= self::MAX_URLS) {
                continue;
            }
            $this->appendEntry($entries, $base . '/post/' . rawurlencode($post->slug), $post->updatedAt);
        }
        foreach ($products as $product) {
            if ($product->status !== ContentStatus::PUBLISHED || count($entries) >= self::MAX_URLS) {
                continue;
            }
            $this->appendEntry($entries, $base . '/product/' . rawurlencode($product->slug), $product->updatedAt);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="' . self::NAMESPACE . '">';
        foreach ($entries as [$url, $lastModified]) {
            $xml .= '<url><loc>' . $this->escapeXml($url) . '</loc>';
            if ($lastModified !== null) {
                $xml .= '<lastmod>' . $lastModified . '</lastmod>';
            }
            $xml .= '</url>';
        }

        return $xml . '</urlset>';
    }

    private function normalizedBaseUrl(): string
    {
        $parts = parse_url(trim($this->siteUrl));
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Sitemap site URL must be an absolute HTTP(S) URL without credentials, query, or fragment.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return $scheme . '://' . $host . $port . $path;
    }

    /** @param array<int, array{string, ?string}> $entries */
    private function appendEntry(array &$entries, string $url, string $updatedAt): void
    {
        if (count($entries) >= self::MAX_URLS || strlen($this->escapeXml($url)) > self::MAX_URL_LENGTH) {
            return;
        }

        $lastModified = null;
        if ($updatedAt !== '') {
            try {
                $lastModified = (new DateTimeImmutable($updatedAt))->format('Y-m-d');
            } catch (Throwable) {
                // Invalid timestamps are omitted rather than fabricated from sitemap generation time.
            }
        }
        $entries[] = [$url, $lastModified];
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
