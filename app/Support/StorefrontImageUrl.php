<?php

namespace App\Support;

class StorefrontImageUrl
{
    private const LEGACY_HOST = 'https://stjacks.com';
    private const DEFAULT_SPACES_URL = 'https://stj-assets.sfo3.cdn.digitaloceanspaces.com';

    public static function image(?string $path, string $folder = 'p400'): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, self::LEGACY_HOST.'/images/')) {
            $path = substr($path, strlen(self::LEGACY_HOST.'/images/'));
        } elseif (str_starts_with($path, '/images/')) {
            $path = substr($path, strlen('/images/'));
        } elseif (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        } else {
            $path = trim($folder, '/').'/'.ltrim($path, '/');
        }

        $path = self::ensureImageExtension(ltrim($path, '/'));
        $baseUrl = self::baseUrl();

        return $baseUrl.'/images/'.$path;
    }

    public static function asset(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, self::LEGACY_HOST.'/images/')) {
            $path = substr($path, strlen(self::LEGACY_HOST.'/images/'));
        } elseif (str_starts_with($path, '/images/')) {
            $path = substr($path, strlen('/images/'));
        } elseif (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return self::baseUrl().'/images/'.ltrim(self::normalizePath($path), '/');
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('filesystems.disks.spaces.url'), '/')
            ?: self::DEFAULT_SPACES_URL;
    }

    private static function ensureImageExtension(string $path): string
    {
        $extension = pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION);

        return $extension === '' ? $path.'.jpg' : $path;
    }

    private static function normalizePath(string $path): string
    {
        $cleanPath = preg_replace('#/+#', '/', '/'.ltrim($path, '/'));

        return is_string($cleanPath) ? $cleanPath : '/';
    }
}
