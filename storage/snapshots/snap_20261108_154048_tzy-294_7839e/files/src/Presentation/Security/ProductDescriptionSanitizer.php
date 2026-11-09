<?php
declare(strict_types=1);

namespace App\Presentation\Security;

final class ProductDescriptionSanitizer
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
        $config->set('HTML.Allowed', 'a[href|title],b,blockquote,br,em,h1,h2,h3,h4,h5,h6,i,li,ol,p,strong,u,ul');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Cache.SerializerPath', $cachePath);

        self::$purifier = new \HTMLPurifier($config);
        return self::$purifier;
    }
}
