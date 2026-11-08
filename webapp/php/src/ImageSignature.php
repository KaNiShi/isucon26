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
    private const int DELEGATE_THRESHOLD = 120;
    private const array REMOTE_HOSTS = ['192.168.2.10', '192.168.3.10'];
    public const string INTERNAL_PATH = '/api/internal/signatures';
    public const string INTERNAL_TOKEN = 'b1f0c6a2-5d7e-4c1a-9e3b-7a4d2f8c6e10';

    /** @var list<array{string, string, int}> */
    private static array $pending = [];

    /** @var array<string, int> */
    private static array $pendingIndexes = [];

    /** @var array<string, string> */
    private static array $memo = [];

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
        $base = hash_init('sha256', HASH_HMAC, $key);
        $value = "{$exp}:{$imageId}";
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $context = hash_copy($base);
            hash_update($context, $value);
            $value = hash_final($context, true);
        }
        return $value;
    }

    public static function url(User $user, string $imageId): string
    {
        $exp = time() + self::URL_LIFETIME;
        $hexKey = bin2hex($user->imageKey());
        $identity = "{$hexKey}|{$imageId}|{$exp}";
        if (self::delegating()) {
            $index = self::$pendingIndexes[$identity] ?? null;
            if ($index === null) {
                $index = count(self::$pending);
                self::$pending[] = [$hexKey, $imageId, $exp];
                self::$pendingIndexes[$identity] = $index;
            }
            $signature = '@@isig' . $index . '@@';
        } else {
            $signature = self::$memo[$identity] ??= self::base64UrlEncode(self::sign($user->imageKey(), $imageId, $exp));
        }
        return '/api/images/' . rawurlencode($imageId) . '?exp=' . $exp . '&sig=' . $signature;
    }

    public static function resolve(string $json): string
    {
        if (self::$pending === []) {
            return $json;
        }
        $pending = self::$pending;
        self::$pending = [];
        self::$pendingIndexes = [];
        $signatures = count($pending) >= self::DELEGATE_THRESHOLD ? self::signRemotely($pending) : [];
        $replacements = [];
        foreach ($pending as $index => [$hexKey, $imageId, $exp]) {
            $signature = $signatures[$index] ?? self::sign((string) hex2bin($hexKey), $imageId, $exp);
            $replacements['@@isig' . $index . '@@'] = self::base64UrlEncode($signature);
        }
        return strtr($json, $replacements);
    }

    /**
     * @param list<array{string, string, int}> $items
     * @return array<int, string>
     */
    public static function signBatch(array $items): array
    {
        return array_map(
            static fn (array $item): string => self::sign((string) hex2bin($item[0]), $item[1], $item[2]),
            $items,
        );
    }

    private static function delegating(): bool
    {
        return getenv('ISUREAL_REGION') === 'asia';
    }

    /**
     * @param list<array{string, string, int}> $pending
     * @return array<int, string>
     */
    private static function signRemotely(array $pending): array
    {
        $hosts = self::REMOTE_HOSTS;
        $chunks = array_chunk($pending, (int) ceil(count($pending) / count($hosts)), preserve_keys: true);
        $multi = curl_multi_init();
        $handles = [];
        foreach ($chunks as $position => $chunk) {
            $handle = curl_init('http://' . $hosts[$position % count($hosts)] . self::INTERNAL_PATH);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(array_values($chunk), JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Internal-Token: ' . self::INTERNAL_TOKEN],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 3000,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$position] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0);
        $signatures = [];
        foreach ($handles as $position => $handle) {
            $body = curl_multi_getcontent($handle);
            $decoded = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200 && is_string($body) ? json_decode($body, true) : null;
            $keys = array_keys($chunks[$position]);
            if (is_array($decoded) && count($decoded) === count($keys)) {
                foreach ($keys as $offset => $index) {
                    $signature = base64_decode((string) $decoded[$offset], true);
                    if ($signature !== false && strlen($signature) === 32) {
                        $signatures[$index] = $signature;
                    }
                }
            }
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
        return $signatures;
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
