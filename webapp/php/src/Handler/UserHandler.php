<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Database;
use Isureal\Http\ApiError;
use Isureal\Http\Input;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Model\User;
use Isureal\Repository\PostRepository;
use Normalizer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class UserHandler
{
    public function __construct(
        private Database $db,
        private PostRepository $posts,
    ) {
    }

    public static function normalizeSearchText(string $value): string
    {
        $folded = mb_convert_case((string) Normalizer::normalize($value, Normalizer::NFC), MB_CASE_FOLD, 'UTF-8');
        return (string) Normalizer::normalize($folded, Normalizer::NFC);
    }

    /**
     * GET /api/users
     */
    public function search(Request $request, Response $response): Response
    {
        $q = Input::queryString($request, 'q');
        $beforeUsername = Input::queryString($request, 'beforeUsername');
        $beforeId = Input::queryString($request, 'beforeId');
        if (!User::validText($q, 1, 100)) {
            throw new ApiError(400, 'invalid_relationship');
        }
        if (($beforeUsername === '') !== ($beforeId === '')) {
            throw new ApiError(400, 'invalid_pagination');
        }
        $viewer = SessionMiddleware::session($request)->userId;
        $blocked = $this->posts->blockedUserIds($viewer);
        $query = self::normalizeSearchText($q);
        $items = [];
        // 全員を一度に読まず、ユーザー名の順に少しずつ読んで、51 件そろったところでやめる。
        // 一致の判定（大文字小文字のそろえ方）は SQL では同じにできないので PHP で行う
        $chunk = 500;
        do {
            $rows = $this->db->fetchAll(
                'SELECT ' . User::columns() . " FROM users
                 WHERE status = 'active' AND id <> :viewer AND (:before_username = '' OR (username, id) > (:before_username, :before_id))
                 ORDER BY username, id LIMIT {$chunk}",
                ['viewer' => $viewer, 'before_username' => $beforeUsername, 'before_id' => $beforeId],
            );
            foreach ($rows as $row) {
                $user = User::fromRow($row);
                $beforeUsername = $user->username;
                $beforeId = $user->id;
                if (!str_contains(self::normalizeSearchText($user->username), $query) && !str_contains(self::normalizeSearchText($user->displayName), $query)) {
                    continue;
                }
                if (isset($blocked[$user->id])) {
                    continue;
                }
                if (!$user->isCertified()) {
                    $user->bio = '';
                }
                $items[] = $user->toPublic();
                if (count($items) === 51) {
                    break 2;
                }
            }
        } while (count($rows) === $chunk);
        return Json::respond($response, self::page($items));
    }

    /**
     * GET /api/users/{userId}
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $viewer = SessionMiddleware::session($request)->userId;
        // ユーザーと、ブロック・友達の関係を 1 回の SQL で調べる
        $row = $this->db->fetchOne(
            'SELECT ' . User::columns() . ",
               EXISTS(
                 SELECT 1 FROM block_relations
                 WHERE (blocker_id = :viewer AND blocked_id = users.id) OR (blocker_id = users.id AND blocked_id = :viewer)
               ) AS blocked,
               EXISTS(
                 SELECT 1 FROM friendships
                 WHERE (user_low_id = :viewer AND user_high_id = users.id) OR (user_low_id = users.id AND user_high_id = :viewer)
               ) AS friend
             FROM users WHERE id = :id AND status = 'active'",
            ['id' => $args['userId'], 'viewer' => $viewer],
        ) ?? throw new ApiError(404, 'relationship_not_found');
        $user = User::fromRow($row);
        if ((bool) $row['blocked']) {
            throw new ApiError(404, 'relationship_not_found');
        }
        if (!$user->isCertified() && !(bool) $row['friend']) {
            $user->bio = '';
        }
        return Json::respond($response, $user->toPublic());
    }

    /**
     * 51 件まで集めた一覧を 50 件の 1 ページにする
     *
     * @param list<mixed> $items
     * @return array{items: list<mixed>, hasMore: bool}
     */
    public static function page(array $items): array
    {
        return ['items' => array_slice($items, 0, 50), 'hasMore' => count($items) > 50];
    }
}
