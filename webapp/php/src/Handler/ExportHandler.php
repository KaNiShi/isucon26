<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Bytea;
use Isureal\Database;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\ImageCache;
use Isureal\Model\Post;
use Isureal\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use ZipArchive;

final readonly class ExportHandler
{
    public function __construct(private Database $db, private UserRepository $users, private ImageCache $cache)
    {
    }

    /**
     * GET /api/me/export
     */
    public function export(Request $request, Response $response): Response
    {
        $userId = SessionMiddleware::session($request)->userId;
        $author = $this->users->mustFind($userId);
        // 画像の本体はここでは読まない。画像キャッシュ（ディスク）にある画像は、ファイルのまま ZIP に入れる
        $rows = $this->db->fetchAll(
            'SELECT ' . Post::COLUMNS . ', i.created_at AS image_created_at
             FROM posts p
             JOIN posting_windows w ON w.id = p.posting_window_id
             JOIN image_objects i ON i.id = p.image_id
             WHERE p.author_id = :author_id AND p.deleted_at IS NULL
             ORDER BY p.posted_at, p.id',
            ['author_id' => $userId],
        );
        $notCached = $this->cacheImages($rows);

        $path = (string) tempnam(sys_get_temp_dir(), 'isureal-export-');
        try {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('failed to create zip');
            }
            $items = [];
            foreach ($rows as $row) {
                $imagePath = sprintf('images/%06d.jpg', count($items) + 1);
                $createdAt = (string) $row['image_created_at'];
                $data = $notCached["{$row['image_id']}\0{$createdAt}"] ?? null;
                $added = $data === null
                    ? $zip->addFile($this->cache->path($row['image_id'], $createdAt), $imagePath)
                    : $zip->addFromString($imagePath, $data);
                if (!$added) {
                    throw new \RuntimeException('failed to add image to zip');
                }
                $zip->setCompressionName($imagePath, ZipArchive::CM_STORE);
                $items[] = [...Post::fromRow($row)->toResponse($author), 'imagePath' => $imagePath];
            }
            $zip->addFromString('posts.json', Json::encode($items) . "\n");
            if (!$zip->close()) {
                throw new \RuntimeException('failed to write zip');
            }
            // 作った ZIP をメモリに読み込まず、ファイルのままレスポンスとして流す。
            // 開いてから消しても、閉じるまでは読める
            $file = fopen($path, 'rb') ?: throw new \RuntimeException('failed to open zip');
            $response = $response->withBody(new Stream($file));
        } finally {
            @unlink($path);
        }
        return $response
            ->withHeader('Content-Disposition', 'attachment; filename="isureal-posts.zip"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Type', 'application/zip');
    }

    /**
     * 画像キャッシュにない画像を DB から読んでキャッシュに書く。
     * キャッシュに書けなかった（使わない設定、ディスクの空き不足など）画像の本体を返す
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, string> "{画像 ID}\0{created_at}" => 本体
     */
    private function cacheImages(array $rows): array
    {
        $missing = [];
        foreach ($rows as $row) {
            if (!$this->cache->exists($row['image_id'], (string) $row['image_created_at'])) {
                $missing[] = (string) $row['image_id'];
            }
        }
        $notCached = [];
        // 一度にメモリに載せる本体の量を抑えるため、少しずつ読む
        foreach (array_chunk($missing, 16) as $ids) {
            [$placeholders, $params] = Database::inClause('id', $ids);
            foreach ($this->db->fetchAll("SELECT id, created_at, data FROM image_objects WHERE id IN ({$placeholders})", $params) as $image) {
                $createdAt = (string) $image['created_at'];
                $data = Bytea::read($image['data']);
                $this->cache->put($image['id'], $createdAt, $data);
                if (!$this->cache->exists($image['id'], $createdAt)) {
                    $notCached["{$image['id']}\0{$createdAt}"] = $data;
                }
            }
        }
        return $notCached;
    }
}
