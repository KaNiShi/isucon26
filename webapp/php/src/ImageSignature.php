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

    /** APCu に署名を置いておく秒数 */
    private const int APCU_TTL = 600;

    /** リクエストの中で覚えておく署名の数 */
    private const int REQUEST_CACHE_SIZE = 256;

    /** @var array<string, string> リクエストの中で覚えておく署名 */
    private static array $signatures = [];

    private static ?bool $apcuEnabled = null;

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
        // 期限を EXP_STEP 秒単位で切り上げる。同じ画像なら、その間は同じ URL になり署名を使い回せる。
        // 切り上げなので、URL の有効期間が URL_LIFETIME より短くなることはない
        $exp = intdiv(time() + self::URL_LIFETIME + self::EXP_STEP - 1, self::EXP_STEP) * self::EXP_STEP;
        $key = $user->imageKey();
        $cacheKey = self::cacheKey($key, $imageId, $exp);
        $signature = self::cached($cacheKey);
        if ($signature === null) {
            $signature = self::sign($key, $imageId, $exp);
            self::remember($cacheKey, $signature);
        }

        return '/api/images/' . rawurlencode($imageId) . '?' . http_build_query([
            'exp' => $exp,
            'sig' => self::base64UrlEncode($signature),
        ]);
    }

    /**
     * 署名が正しいか確かめる。sign() と同じ結果になるが、一度正しいとわかった署名は計算し直さない
     */
    public static function verify(string $key, string $imageId, int $exp, string $signature): bool
    {
        $cacheKey = self::cacheKey($key, $imageId, $exp);
        $expected = self::cached($cacheKey);
        if ($expected !== null) {
            return hash_equals($expected, $signature);
        }
        $expected = self::sign($key, $imageId, $exp);
        if (!hash_equals($expected, $signature)) {
            // 不正な署名の計算結果は覚えない（でたらめな期限でキャッシュを埋められないように）
            return false;
        }
        self::remember($cacheKey, $expected);
        return true;
    }

    /**
     * 署名のキャッシュの鍵。鍵・画像 ID・期限が同じなら署名も同じなので、この 3 つから作る。
     * 署名の鍵そのものは残さない
     */
    private static function cacheKey(string $key, string $imageId, int $exp): string
    {
        return 'isureal:image-signature:' . hash_hmac('sha256', "image-url-cache\0{$exp}:{$imageId}", $key);
    }

    /**
     * キャッシュした署名を返す。なければ null。
     * リクエストの中のキャッシュを先に見て、なければ APCu（このサーバーの全リクエストで共有）を見る
     */
    private static function cached(string $cacheKey): ?string
    {
        if (isset(self::$signatures[$cacheKey])) {
            return self::$signatures[$cacheKey];
        }
        if (!self::apcuEnabled()) {
            return null;
        }
        $signature = apcu_fetch($cacheKey, $found);
        if (!$found || !is_string($signature) || strlen($signature) !== 32) {
            return null;
        }
        self::rememberInRequest($cacheKey, $signature);
        return $signature;
    }

    private static function remember(string $cacheKey, string $signature): void
    {
        self::rememberInRequest($cacheKey, $signature);
        if (self::apcuEnabled()) {
            // 容量が足りなければ APCu が古いものを捨てる。捨てられても計算し直すだけ
            apcu_store($cacheKey, $signature, self::APCU_TTL);
        }
    }

    private static function rememberInRequest(string $cacheKey, string $signature): void
    {
        if (count(self::$signatures) < self::REQUEST_CACHE_SIZE) {
            self::$signatures[$cacheKey] = $signature;
        }
    }

    private static function apcuEnabled(): bool
    {
        return self::$apcuEnabled ??= function_exists('apcu_enabled') && apcu_enabled();
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
