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
    private const MAX_IMAGES_PER_URL = 1000;
    private const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const IMAGE_NAMESPACE = 'http://www.google.com/schemas/sitemap-image/1.1';

    public function __construct(private string $siteUrl)
    {
    }

    /**
     * @param Post[] $posts Published posts only
     * @param Product[] $products Products whose published state is checked by the builder
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
            $images = array_merge([$product->featuredImage], $product->galleryImages);
            $this->appendEntry(
                $entries,
                $base . '/product/' . rawurlencode($product->slug),
                $product->updatedAt,
                $this->normalizeImageUrls($images, $base)
            );
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="' . self::NAMESPACE . '" xmlns:image="' . self::IMAGE_NAMESPACE . '">';
        foreach ($entries as [$url, $lastModified, $images]) {
            $xml .= '<url><loc>' . $this->escapeXml($url) . '</loc>';
            if ($lastModified !== null) {
                $xml .= '<lastmod>' . $lastModified . '</lastmod>';
            }
            foreach ($images as $imageUrl) {
                $xml .= '<image:image><image:loc>' . $this->escapeXml($imageUrl) . '</image:loc></image:image>';
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

    /** @param array<int, array{string, ?string, string[]}> $entries
     *  @param string[] $images
     */
    private function appendEntry(array &$entries, string $url, string $updatedAt, array $images = []): void
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
        $entries[] = [$url, $lastModified, $images];
    }

    /** @param string[] $images
     *  @return string[]
     */
    private function normalizeImageUrls(array $images, string $base): array
    {
        $normalized = [];
        foreach ($images as $image) {
            $image = trim($image);
            if ($image === '' || preg_match('/[\x00-\x20\x7f]/', $image) === 1) {
                continue;
            }

            if (str_starts_with($image, '/') && !str_starts_with($image, '//')) {
                $imageUrl = $base . $image;
            } else {
                $parts = parse_url($image);
                if (!is_array($parts)
                    || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                    || empty($parts['host'])
                    || isset($parts['user'])
                    || isset($parts['pass'])
                    || filter_var($image, FILTER_VALIDATE_URL) === false) {
                    continue;
                }
                $imageUrl = $image;
            }

            if (strlen($this->escapeXml($imageUrl)) > self::MAX_URL_LENGTH || isset($normalized[$imageUrl])) {
                continue;
            }
            $normalized[$imageUrl] = true;
            if (count($normalized) >= self::MAX_IMAGES_PER_URL) {
                break;
            }
        }

        return array_keys($normalized);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
