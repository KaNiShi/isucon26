<?php

declare(strict_types=1);

namespace Isureal;

/**
 * 画像 URL の署名を、このサーバーのディスクに置くキャッシュ。リクエストをまたいで使い回す。
 *
 * 署名は鍵・画像 ID・期限だけで決まり、ファイル名はその 3 つから作るので、ここにある署名が間違っていることはない。
 * 消えても壊れても計算し直すだけで済む。失敗はすべて「キャッシュなし」として扱い、例外を外に出さない。
 *
 * 置き場所は {dir}/{期限}/{ハッシュ}。期限ごとのフォルダを最初に作ったリクエストが、
 * レスポンスを返したあとで KEEP_SECONDS より古いフォルダを消す。
 */
final class ImageSignatureCache
{
    /** 発行してからこの秒数を過ぎた署名のフォルダを消す */
    private const int KEEP_SECONDS = 600;

    private const int SIGNATURE_LENGTH = 32;

    private static ?string $dir = null;

    public static function get(int $exp, string $hash): ?string
    {
        $dir = self::dir();
        if ($dir === '') {
            return null;
        }
        $signature = @file_get_contents("{$dir}/{$exp}/{$hash}");
        return $signature !== false && strlen($signature) === self::SIGNATURE_LENGTH ? $signature : null;
    }

    /**
     * @param bool $createBucket false なら、期限のフォルダがまだなければ書かない（古い期限のフォルダを作り直さないため）
     */
    public static function put(int $exp, string $hash, string $signature, bool $createBucket): void
    {
        $dir = self::dir();
        if ($dir === '') {
            return;
        }
        $bucket = "{$dir}/{$exp}";
        if (!is_dir($bucket)) {
            if (!$createBucket) {
                return;
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (@mkdir($bucket, 0700)) {
                // この期限のフォルダを作れたリクエストだけが片付ける（1 期限に 1 回）
                register_shutdown_function(self::cleanup(...), $dir, $exp - self::KEEP_SECONDS);
            } elseif (!is_dir($bucket)) {
                return;
            }
        }
        // 一時ファイルに書いてから名前を変えるので、読む側が書きかけを見ることはない
        $tmp = @tempnam($bucket, '.tmp-');
        if ($tmp === false) {
            return;
        }
        if (@file_put_contents($tmp, $signature) !== self::SIGNATURE_LENGTH || !@rename($tmp, "{$bucket}/{$hash}")) {
            @unlink($tmp);
        }
    }

    /**
     * initialize で、このサーバーのキャッシュを消す。消すのはレスポンスを返したあと。
     * 初期化の直後から負荷走行の書き込みが始まるので、いまの期限のフォルダは残す（1 分ごとの片付けで消える）
     */
    public static function clear(): void
    {
        $dir = self::dir();
        if ($dir !== '') {
            register_shutdown_function(self::cleanup(...), $dir, ImageSignature::currentExp());
        }
    }

    /**
     * $before より前の期限のフォルダを消す。終了処理から呼び、先にレスポンスを返しておく
     */
    public static function cleanup(string $dir, int $before): void
    {
        foreach (['frankenphp_finish_request', 'fastcgi_finish_request'] as $finish) {
            if (function_exists($finish)) {
                $finish();
                break;
            }
        }
        foreach (@scandir($dir) ?: [] as $name) {
            if (ctype_digit($name) && (int) $name < $before) {
                // 名前を変えて切り離してから消す。ほかのリクエストと同じフォルダを消し始めないように
                $trash = "{$dir}/.trash-{$name}-" . bin2hex(random_bytes(4));
                if (!@rename("{$dir}/{$name}", $trash)) {
                    continue;
                }
            } elseif (str_starts_with($name, '.trash-')) {
                // 前の片付けが途中で終わったもの
                $trash = "{$dir}/{$name}";
            } else {
                continue;
            }
            foreach (@scandir($trash) ?: [] as $file) {
                if ($file !== '.' && $file !== '..') {
                    @unlink("{$trash}/{$file}");
                }
            }
            @rmdir($trash);
        }
    }

    /**
     * ISUREAL_IMAGE_SIGNATURE_CACHE_DIR が off ならキャッシュを使わない。未設定なら一時ディレクトリの下に置く
     */
    private static function dir(): string
    {
        if (self::$dir === null) {
            $dir = getenv('ISUREAL_IMAGE_SIGNATURE_CACHE_DIR');
            $dir = $dir === false || $dir === '' ? sys_get_temp_dir() . '/isureal-image-signatures' : $dir;
            self::$dir = $dir === 'off' ? '' : rtrim($dir, '/');
        }
        return self::$dir;
    }
}
