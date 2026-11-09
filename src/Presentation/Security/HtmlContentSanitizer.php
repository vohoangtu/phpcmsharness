<?php
declare(strict_types=1);

namespace App\Presentation\Security;

final class HtmlContentSanitizer
{
    private static ?\HTMLPurifier $purifier = null;

    public static function sanitize(string $html): string
    {
        return self::purifier()->purify($html);
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $cachePath = dirname(__DIR__, 3) . '/storage/cache/htmlpurifier';
        if (!is_dir($cachePath) && !mkdir($cachePath, 0700, true) && !is_dir($cachePath)) {
            throw new \RuntimeException('Unable to create the HTML sanitizer cache directory.');
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', 'a[href|title|target|rel],b,blockquote,br,em,figure,figcaption,h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],i,iframe[src|width|height|allow|allowfullscreen|title],img[src|alt|width|height],li,ol,p[style],strong,table,tbody,td[colspan|rowspan|style],th[colspan|rowspan|style],thead,tr,u,ul');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', '%^(https?:)?//(www\\.)?(youtube-nocookie\\.com/embed/|youtube\\.com/embed/)%');
        $config->set('Cache.SerializerPath', $cachePath);

        self::$purifier = new \HTMLPurifier($config);
        return self::$purifier;
    }
}
