<?php

declare(strict_types=1);

namespace Isureal;

/**
 * 画像本体をこのサーバーのディスクに置くキャッシュ。
 *
 * 正は DB の image_objects で、ここにあるのは写しにすぎない。消えても壊れても DB から取り直すだけで済む。
 * 署名・期限・削除状態の確認は DB で毎回行い、キャッシュは確認を通った画像の本体を返すときだけ使う。
 *
 * image_objects の行は書き換えられないので、(id, created_at) が同じなら本体も同じ。
 * created_at も鍵に含めるので、初期化で消えた行と同じ id の行ができても古い本体は返さない。
 *
 * 失敗はすべて「キャッシュなし」として扱い、例外を外に出さない。
 */
final readonly class ImageCache
{
    /** ファイルの先頭に置く本体のハッシュ（xxh128）の長さ */
    private const int HASH_LENGTH = 16;

    /** 空きがこれより少ないときは書き込まない */
    private const int MIN_FREE_BYTES = 2 * 1024 * 1024 * 1024;

    /** 空きがディスク全体のこの割合より少ないときは書き込まない */
    private const float MIN_FREE_RATIO = 0.2;

    /**
     * @param string $dir キャッシュを置くディレクトリ。空文字列なら使わない
     */
    public function __construct(private string $dir)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->imageCacheDir);
    }

    public function get(string $imageId, string $createdAt): ?string
    {
        if ($this->dir === '') {
            return null;
        }
        $content = @file_get_contents($this->path($imageId, $createdAt));
        if ($content === false || strlen($content) <= self::HASH_LENGTH) {
            return null;
        }
        $data = substr($content, self::HASH_LENGTH);
        // 書き込みの途中で落ちたなどで壊れていれば使わない
        if (!hash_equals(substr($content, 0, self::HASH_LENGTH), hash('xxh128', $data, true))) {
            return null;
        }
        return $data;
    }

    public function put(string $imageId, string $createdAt, string $data): void
    {
        if ($this->dir === '' || $data === '') {
            return;
        }
        $path = $this->path($imageId, $createdAt);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        if (!$this->hasEnoughSpace($dir)) {
            return;
        }
        // 別の名前で書き切ってから置き換えるので、読む側が書きかけのファイルを見ることはない
        $tmp = @tempnam($dir, '.tmp-');
        if ($tmp === false) {
            return;
        }
        $content = hash('xxh128', $data, true) . $data;
        if (@file_put_contents($tmp, $content) !== strlen($content) || !@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    private function path(string $imageId, string $createdAt): string
    {
        $key = hash('sha256', "{$imageId}\0{$createdAt}");
        return $this->dir . '/' . substr($key, 0, 2) . '/' . $key;
    }

    /**
     * キャッシュでディスクを使い切ってサービスを止めないよう、空きが十分なときだけ書き込む
     */
    private function hasEnoughSpace(string $dir): bool
    {
        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        if ($free === false || $total === false) {
            return false;
        }
        return $free >= self::MIN_FREE_BYTES && $free >= $total * self::MIN_FREE_RATIO;
    }
}
