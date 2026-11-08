<?php

declare(strict_types=1);

namespace Isureal;

final class ImageStore
{
    public const string DIRECTORY = '/home/isucon/image-store';

    public static function valid(string $imageId): bool
    {
        return preg_match('/\A[0-9a-f-]{36}\z/', $imageId) === 1;
    }

    public static function exists(string $imageId): bool
    {
        return self::valid($imageId) && is_file(self::path($imageId));
    }

    public static function read(string $imageId): ?string
    {
        if (!self::valid($imageId)) {
            return null;
        }
        $data = @file_get_contents(self::path($imageId));
        return $data === false ? null : $data;
    }

    public static function write(string $imageId, string $data): void
    {
        if (!self::valid($imageId)) {
            return;
        }
        $path = self::path($imageId);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $data) === strlen($data)) {
            @rename($temporary, $path);
        } else {
            @unlink($temporary);
        }
    }

    public static function clear(): void
    {
        if (!is_dir(self::DIRECTORY)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::DIRECTORY, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
    }

    private static function path(string $imageId): string
    {
        return self::DIRECTORY . '/' . substr($imageId, 0, 2) . '/' . $imageId;
    }
}
