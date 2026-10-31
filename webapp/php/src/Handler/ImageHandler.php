<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Bytea;
use Isureal\Database;
use Isureal\Http\ApiError;
use Isureal\Http\ActionLogEntry;
use Isureal\Http\ClientIp;
use Isureal\Http\ErrorHandler;
use Isureal\Http\Input;
use Isureal\ImageCache;
use Isureal\ImageSignature;
use Isureal\Repository\IpBanRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class ImageHandler
{
    public function __construct(private Database $db, private ImageCache $cache)
    {
    }

    /**
     * GET /api/images/{imageId}
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $ipBanChecked = false;
        try {
            $response = $this->image($request, $response, $args['imageId'], $ipBanChecked);
        } catch (\Throwable $e) {
            // このルートには IpBanMiddleware を付けず、IP BAN は署名の鍵と一緒に調べる。
            // BAN の 403 は他の誤りより先に、他の API と同じ形（Cache-Control なし）で返す
            if ($e instanceof ApiError && $e->error === 'ip_banned') {
                throw $e;
            }
            if (!$ipBanChecked && new IpBanRepository($this->db)->isBanned(ClientIp::of($request))) {
                throw new ApiError(403, 'ip_banned');
            }
            $response = ErrorHandler::render($response, $e);
        }
        return $response->withHeader('Cache-Control', 'no-store');
    }

    private function image(Request $request, Response $response, string $imageId, bool &$ipBanChecked): Response
    {
        $query = Input::parseQuery(Input::rawQuery($request), strict: true);
        if ($query === null || count($query['exp'] ?? []) !== 1 || count($query['sig'] ?? []) !== 1) {
            throw self::forbidden();
        }
        [$exp, $sig] = [$query['exp'][0], $query['sig'][0]];
        // int64 の範囲の正規化された 10 進数だけを受け付ける（負の値は期限切れとして扱う）
        $expiresAt = filter_var($exp, FILTER_VALIDATE_INT);
        if ($expiresAt === false || (string) $expiresAt !== $exp || $expiresAt <= time()) {
            throw self::forbidden();
        }
        $signature = ImageSignature::base64UrlDecode($sig);
        if ($signature === null || strlen($signature) !== 32) {
            throw self::forbidden();
        }

        // IP BAN、署名の鍵、削除状態を 1 回の問い合わせで確かめる。本体（data）はここでは読まない。
        // 削除状態は毎回 DB で確認する。即時反映も5秒以内の許容範囲に含まれる。
        $meta = $this->db->fetchOne(
            "SELECT b.banned, m.secret_key_hash, m.media_type, m.created_at, m.visible
             FROM (SELECT EXISTS(SELECT 1 FROM ip_bans WHERE ip = :ip AND banned_until > clock_timestamp()) AS banned) b
             LEFT JOIN LATERAL (
             SELECT u.secret_key_hash, i.media_type, i.created_at,
               coalesce(
                 u.profile_image_id = i.id
                 OR EXISTS (
                   SELECT 1 FROM posts p
                   WHERE p.image_id = i.id AND p.author_id = u.id AND p.deleted_at IS NULL
                 )
                 OR EXISTS (
                   SELECT 1 FROM realmojis r
                   JOIN posts p ON p.id = r.post_id AND p.deleted_at IS NULL
                   JOIN users author ON author.id = p.author_id AND author.status = 'active'
                   WHERE r.image_id = i.id AND r.reactor_id = u.id AND r.replaced_at IS NULL AND r.deleted_at IS NULL
                 ),
                 false
               ) AS visible
             FROM image_objects i JOIN users u ON u.id = i.owner_user_id
             WHERE i.id = :id AND u.status = 'active'
             ) m ON true",
            ['id' => $imageId, 'ip' => ClientIp::of($request)],
        ) ?? throw new \RuntimeException('sql: no rows in result set');
        $ipBanChecked = true;
        if ((bool) $meta['banned']) {
            throw new ApiError(403, 'ip_banned');
        }
        if ($meta['secret_key_hash'] === null) {
            throw self::forbidden();
        }
        $key = ImageSignature::signingKey($meta['secret_key_hash']);
        if (!ImageSignature::verify($key, $imageId, $expiresAt, $signature)) {
            ActionLogEntry::of($request)->abuse = 'invalid_signature';
            throw self::forbidden();
        }
        if ($meta['visible'] !== true) {
            throw self::forbidden();
        }
        if ($expiresAt <= time()) {
            throw self::forbidden();
        }

        $createdAt = (string) $meta['created_at'];
        $exists = $this->cache->exists($imageId, $createdAt);
        if (!$exists) {
            $value = $this->db->fetchValue(
                'SELECT data FROM image_objects WHERE id = :id AND created_at = :created_at',
                ['id' => $imageId, 'created_at' => $createdAt],
            ) ?? throw self::forbidden();
            $data = Bytea::read($value);
            $this->cache->put($imageId, $createdAt, $data);
        }

        return $response
            ->withHeader('X-Accel-Redirect', $this->cache->path($imageId, $createdAt))
            ->withHeader('Content-Type', $meta['media_type']);
    }

    private static function forbidden(): ApiError
    {
        return new ApiError(403, 'image_forbidden');
    }
}
