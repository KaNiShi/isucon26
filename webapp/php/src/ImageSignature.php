<?php

declare(strict_types=1);

namespace Isureal;

use Isureal\Model\User;

/**
 * 画像の署名付き URL
 */
final class ImageSignature
{
    public const int URL_LIFETIME = 24 * 60 * 60;
    private const int ITERATIONS = 1000;

    /** 発行する URL の期限をこの秒数単位で切り上げる */
    private const int EXP_STEP = 60;

    /** リクエストの中で覚えておく署名の数 */
    private const int REQUEST_CACHE_SIZE = 256;

    /** @var array<string, string> リクエストの中で覚えておく署名 */
    private static array $signatures = [];

    /**
     * secret_key_hash（16 進数の SHA-256）を署名の鍵にする
     */
    public static function signingKey(string $secretKeyHash): string
    {
        if (!preg_match('/\A[0-9a-fA-F]{64}\z/', $secretKeyHash)) {
            throw new \UnexpectedValueException('invalid image signing key');
        }
        return (string) hex2bin($secretKeyHash);
    }

    public static function sign(string $key, string $imageId, int $exp): string
    {
        $value = "{$exp}:{$imageId}";
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $value = hash_hmac('sha256', $value, $key, true);
        }
        return $value;
    }

    public static function url(User $user, string $imageId): string
    {
        $exp = self::currentExp();
        $key = $user->imageKey();
        $hash = self::cacheHash($key, $imageId, $exp);
        $signature = self::cached($exp, $hash);
        if ($signature === null) {
            $signature = self::sign($key, $imageId, $exp);
            self::remember($exp, $hash, $signature, createBucket: true);
        }

        return '/api/images/' . rawurlencode($imageId) . '?' . http_build_query([
            'exp' => $exp,
            'sig' => self::base64UrlEncode($signature),
        ]);
    }

    /**
     * いま発行する URL の期限。EXP_STEP 秒単位で切り上げるので、同じ画像ならその間は同じ URL になり署名を使い回せる。
     * 切り上げなので、URL の有効期間が URL_LIFETIME より短くなることはない
     */
    public static function currentExp(): int
    {
        return intdiv(time() + self::URL_LIFETIME + self::EXP_STEP - 1, self::EXP_STEP) * self::EXP_STEP;
    }

    /**
     * 署名が正しいか確かめる。sign() と同じ結果になるが、一度正しいとわかった署名は計算し直さない
     */
    public static function verify(string $key, string $imageId, int $exp, string $signature): bool
    {
        $hash = self::cacheHash($key, $imageId, $exp);
        $expected = self::cached($exp, $hash);
        if ($expected !== null) {
            return hash_equals($expected, $signature);
        }
        $expected = self::sign($key, $imageId, $exp);
        if (!hash_equals($expected, $signature)) {
            // 不正な署名の計算結果は覚えない（でたらめな期限でキャッシュを埋められないように）
            return false;
        }
        // 期限のフォルダがなければ作らない。古い期限のフォルダを作り直すと片付けが追いつかなくなる
        self::remember($exp, $hash, $expected, createBucket: false);
        return true;
    }

    /**
     * 署名のキャッシュの鍵。鍵・画像 ID・期限が同じなら署名も同じなので、この 3 つから作る。
     * 署名の鍵そのものは残さない
     */
    private static function cacheHash(string $key, string $imageId, int $exp): string
    {
        return hash_hmac('sha256', "image-url-cache\0{$exp}:{$imageId}", $key);
    }

    /**
     * キャッシュした署名を返す。なければ null。リクエストの中のキャッシュを先に見て、なければディスクを見る
     */
    private static function cached(int $exp, string $hash): ?string
    {
        $requestKey = "{$exp}:{$hash}";
        if (isset(self::$signatures[$requestKey])) {
            return self::$signatures[$requestKey];
        }
        $signature = ImageSignatureCache::get($exp, $hash);
        if ($signature !== null) {
            self::rememberInRequest($requestKey, $signature);
        }
        return $signature;
    }

    private static function remember(int $exp, string $hash, string $signature, bool $createBucket): void
    {
        self::rememberInRequest("{$exp}:{$hash}", $signature);
        ImageSignatureCache::put($exp, $hash, $signature, $createBucket);
    }

    private static function rememberInRequest(string $requestKey, string $signature): void
    {
        if (count(self::$signatures) < self::REQUEST_CACHE_SIZE) {
            self::$signatures[$requestKey] = $signature;
        }
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * パディングなしの base64url を厳密に読む。読めなければ null
     */
    public static function base64UrlDecode(string $value): ?string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]*\z/', $value)) {
            return null;
        }
        $data = base64_decode(strtr($value, '-_', '+/'), true);
        return $data === false || self::base64UrlEncode($data) !== $value ? null : $data;
    }
}
