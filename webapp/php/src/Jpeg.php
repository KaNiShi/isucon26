<?php

declare(strict_types=1);

namespace Isureal;

final class Jpeg
{
    /** マーカーの走査で読み進める上限。これを超えても SOF が出なければ不正とみなす */
    private const int MAX_SCAN_BYTES = 1024 * 1024;

    /**
     * @return array{int, int}|null
     */
    public static function size(string $data): ?array
    {
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

    /**
     * 本文を全部読まずに、先頭のマーカーだけをたどって [幅, 高さ] を返す。SOF が見つからなければ null
     *
     * @return array{int, int}|null
     */
    public static function sizeFromStream(\Psr\Http\Message\StreamInterface $stream): ?array
    {
        $read = 0;
        $take = static function (int $n) use ($stream, &$read): ?string {
            $read += $n;
            if ($read > self::MAX_SCAN_BYTES) {
                return null;
            }
            $s = '';
            while (strlen($s) < $n) {
                $chunk = $stream->read($n - strlen($s));
                if ($chunk === '') {
                    return null;
                }
                $s .= $chunk;
            }
            return $s;
        };
        if ($take(2) !== "\xFF\xD8") {
            return null;
        }
        while (true) {
            $b = $take(1);
            if ($b === null) {
                return null;
            }
            if ($b !== "\xFF") {
                continue;
            }
            do {
                $m = $take(1);
                if ($m === null) {
                    return null;
                }
            } while ($m === "\xFF");
            $code = ord($m);
            if ($code === 0x00 || $code === 0x01 || ($code >= 0xD0 && $code <= 0xD8)) {
                continue; // 長さを持たないマーカー
            }
            if ($code === 0xD9 || $code === 0xDA) {
                return null; // SOF より前に終端・スキャン開始
            }
            $lenBytes = $take(2);
            $len = $lenBytes === null ? 0 : unpack('n', $lenBytes)[1];
            if ($len < 2) {
                return null;
            }
            if ($code >= 0xC0 && $code <= 0xCF && !in_array($code, [0xC4, 0xC8, 0xCC], true)) {
                $sof = $take(5);
                if ($sof === null) {
                    return null;
                }
                ['h' => $h, 'w' => $w] = unpack('Cp/nh/nw', $sof);
                return [$w, $h];
            }
            if ($len > 2 && $take($len - 2) === null) {
                return null;
            }
        }
    }
}

