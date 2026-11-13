<?php

declare(strict_types=1);

namespace App\Application\Extension;

use InvalidArgumentException;

final class ExtensionCatalog
{
    public static function providerFileExists(string $class): bool
    {
        if (class_exists($class, false)) return true;
        if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) return false;
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            if ($loader->findFile($class) !== false) return true;
        }
        return false;
    }

    /** @param mixed $configured @return list<array{id:string,name:string,version:string,description:string,provider:string}> */
    public static function normalize(mixed $configured): array
    {
        if (!is_array($configured)) throw new InvalidArgumentException('Danh sách extension cần là một mảng cấu hình.');
        $result = []; $seen = [];
        foreach ($configured as $entry) {
            if (is_string($entry)) {
                $entry = ['id'=>strtolower(str_replace('\\', '.', $entry)), 'name'=>$entry, 'version'=>'0.0.0', 'description'=>'', 'provider'=>$entry];
            }
            if (!is_array($entry)) throw new InvalidArgumentException('Mỗi extension cần khai báo manifest dạng mảng.');
            $id = strtolower(trim((string)($entry['id'] ?? '')));
            $provider = ltrim(trim((string)($entry['provider'] ?? '')), '\\');
            $providerParts = explode('\\', $provider);
            $validProvider = $provider !== '';
            foreach ($providerParts as $part) $validProvider = $validProvider && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part) === 1;
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,79}$/', $id) || !$validProvider) {
                throw new InvalidArgumentException('Extension cần có id hợp lệ và provider là tên class PHP.');
            }
            if (isset($seen[$id])) throw new InvalidArgumentException(sprintf('Extension id [%s] bị khai báo trùng.', $id));
            $seen[$id] = true;
            $result[] = [
                'id'=>$id,
                'name'=>trim((string)($entry['name'] ?? $id)),
                'version'=>trim((string)($entry['version'] ?? '0.0.0')),
                'description'=>trim((string)($entry['description'] ?? '')),
                'provider'=>$provider,
            ];
        }
        return $result;
    }
}
