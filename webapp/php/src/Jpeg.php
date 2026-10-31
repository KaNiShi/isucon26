<?php

declare(strict_types=1);

namespace Isureal;

final class Jpeg
{
    /**
     * @return array{int, int}|null
     */
    public static function size(string $data): ?array
    {
        // libjpeg が必須とする SOI マーカーを先に確かめる。
        // 大きな非 JPEG 入力を、検証のためだけにディスクへ書き出さない。
        if (!str_starts_with($data, "\xff\xd8")) {
            return null;
        }

        // imagecreatefromstring() は JPEG 以外も受理し、GD の構成によっては
        // JPEG の警告も無視する。既存の JPEG デコーダと警告設定を維持する。
        ini_set('gd.jpeg_ignore_warning', '0');
        $info = getimagesizefromstring($data);
        return $info ? [$info[0], $info[1]] : null;

        $path = tempnam(sys_get_temp_dir(), 'jpeg') ?: throw new \RuntimeException('failed to create a temporary file');
        try {
            file_put_contents($path, $data);
            $image = @imagecreatefromjpeg($path);
        } finally {
            unlink($path);
        }
        if ($image === false) {
            return null;
        }
        return [imagesx($image), imagesy($image)];
    }
}
