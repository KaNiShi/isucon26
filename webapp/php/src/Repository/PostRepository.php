<?php

declare(strict_types=1);

namespace Isureal\Repository;

use DateTimeImmutable;
use Isureal\Database;
use Isureal\Model\Post;
use Isureal\Model\User;
use Isureal\Model\Visibility;
use Isureal\Model\ViewerContext;
use Isureal\Time;

final readonly class PostRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * 削除されていない投稿を返す
     */
    public function find(string $postId): ?Post
    {
        $row = $this->db->fetchOne(
            'SELECT ' . Post::COLUMNS . '
             FROM posts p JOIN posting_windows w ON w.id = p.posting_window_id
             WHERE p.id = :id AND p.deleted_at IS NULL',
            ['id' => $postId],
        );
        return $row === null ? null : Post::fromRow($row);
    }

    public function mustFind(string $postId): Post
    {
        return $this->find($postId) ?? throw new \RuntimeException('sql: no rows in result set');
    }

    /**
     * 投稿を投稿者と一緒に返す。$conditions は WHERE 句に続ける条件
     *
     * @param array<string, mixed> $params
     * @return list<array{Post, User}>
     */
    public function selectWithAuthor(string $conditions, array $params): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . Post::COLUMNS . ', ' . User::columnsAs('u', 'author') . "
             FROM posts p
             JOIN posting_windows w ON w.id = p.posting_window_id
             JOIN users u ON u.id = p.author_id AND u.status = 'active'
             WHERE p.deleted_at IS NULL {$conditions}",
            $params,
        );
        return array_map(static fn (array $row): array => [Post::fromRow($row), User::fromRow($row, 'author.')], $rows);
    }

    /**
     * @return array{Post, User}|null
     */
    public function findWithAuthor(string $postId): ?array
    {
        return $this->selectWithAuthor('AND p.id = :post_id', ['post_id' => $postId])[0] ?? null;
    }

    /**
     * フィードの投稿を、閲覧条件と並び順を当てはめたうえで最大 $limit 件返す。
     * 閲覧者の確認、友達・ブロック関係、閲覧判定、（友達の友達フィードでは）スコア計算まで 1 回の SQL で済ませる。
     * 閲覧者が退会済みか、今日まだ投稿していなければ空になる（自分の投稿はフィードに出ないため）
     *
     * @param 'friends'|'friends-of-friends'|'public'|'following' $feed
     * @return list<array{Post, User, int}> 投稿、投稿者、スコア（友達の友達フィード以外は 0）
     */
    public function feed(
        string $viewerId,
        string $feed,
        ?int $beforeScore,
        ?DateTimeImmutable $beforePostedAt,
        string $beforeId,
        int $limit,
    ): array {
        $condition = match ($feed) {
            'public' => "p.visibility = 'public'",
            'friends' => "p.author_id IN (SELECT id FROM friends)
                 AND p.visibility IN ('public', 'friends', 'friends_of_friends')",
            'friends-of-friends' => "p.author_id IN (SELECT id FROM mutual)
                 AND p.author_id NOT IN (SELECT id FROM friends)
                 AND p.visibility IN ('public', 'friends_of_friends')",
            'following' => "p.author_id IN (SELECT followee_id FROM follows WHERE follower_id = :viewer_id)
                 AND (p.visibility = 'public'
                      OR (p.visibility = 'friends' AND p.author_id IN (SELECT id FROM friends))
                      OR (p.visibility = 'friends_of_friends'
                          AND (p.author_id IN (SELECT id FROM friends) OR p.author_id IN (SELECT id FROM mutual))))",
        };
        $params = ['viewer_id' => $viewerId, 'limit' => $limit];

        // viewer: 有効で、今日（地域の開催中の投稿期間と同じ日）投稿済みのときだけ 1 行
        // mutual: 友達の友達と、共通の友達の数（閲覧者自身は除く）
        $sql = 'WITH viewer AS (
              SELECT u.id FROM users u
              WHERE u.id = :viewer_id AND u.status = \'active\'
                AND EXISTS(
                  SELECT 1 FROM posts vp
                  JOIN posting_windows posted ON posted.id = vp.posting_window_id
                  JOIN posting_windows cur ON cur.region = u.region AND cur.ended_at IS NULL
                                          AND cur.local_date = posted.local_date
                  WHERE vp.author_id = u.id AND vp.deleted_at IS NULL
                )
            ),
            friends AS (
              SELECT user_high_id AS id FROM friendships WHERE user_low_id = :viewer_id
              UNION
              SELECT user_low_id FROM friendships WHERE user_high_id = :viewer_id
            ),
            blocked AS (
              SELECT blocked_id AS id FROM block_relations WHERE blocker_id = :viewer_id
              UNION ALL
              SELECT blocker_id FROM block_relations WHERE blocked_id = :viewer_id
            ),
            mutual AS (
              SELECT o.id, count(*) AS n FROM (
                SELECT fs.user_high_id AS id FROM friendships fs JOIN friends f ON f.id = fs.user_low_id
                UNION ALL
                SELECT fs.user_low_id FROM friendships fs JOIN friends f ON f.id = fs.user_high_id
              ) o
              WHERE o.id <> :viewer_id
              GROUP BY o.id
            ),
            candidates AS (
              SELECT ' . Post::COLUMNS . ', ' . User::columnsAs('u', 'author') . "
              FROM posts p
              JOIN posting_windows w ON w.id = p.posting_window_id
              JOIN users u ON u.id = p.author_id AND u.status = 'active'
              WHERE EXISTS(SELECT 1 FROM viewer)
                AND p.deleted_at IS NULL AND w.ended_at IS NULL
                AND p.author_id <> :viewer_id
                AND p.author_id NOT IN (SELECT id FROM blocked)
                AND {$condition}
            )";

        if ($feed === 'friends-of-friends') {
            // スコア = 10 × 反応・コメントした友達の数 + 3 × 共通の友達の数 + min(投稿者の友達の共通の友達の数の合計, 30)
            $sql .= ",
            engagements AS (
              SELECT e.post_id, count(*) AS n FROM (
                SELECT r.post_id, r.reactor_id AS user_id FROM realmojis r
                WHERE r.post_id IN (SELECT id FROM candidates) AND r.replaced_at IS NULL AND r.deleted_at IS NULL
                UNION
                SELECT c.post_id, c.author_id FROM comments c
                WHERE c.post_id IN (SELECT id FROM candidates) AND c.deleted_at IS NULL
              ) e
              JOIN friends f ON f.id = e.user_id
              JOIN users eu ON eu.id = e.user_id AND eu.status = 'active'
              GROUP BY e.post_id
            ),
            connections AS (
              SELECT o.author_id, sum(m.n) AS n FROM (
                SELECT fs.user_low_id AS author_id, fs.user_high_id AS other_id FROM friendships fs
                WHERE fs.user_low_id IN (SELECT author_id FROM candidates)
                UNION ALL
                SELECT fs.user_high_id, fs.user_low_id FROM friendships fs
                WHERE fs.user_high_id IN (SELECT author_id FROM candidates)
              ) o
              JOIN mutual m ON m.id = o.other_id
              GROUP BY o.author_id
            ),
            scored AS (
              SELECT c.*, (10 * coalesce(e.n, 0) + 3 * coalesce(m.n, 0) + LEAST(coalesce(cn.n, 0), 30))::bigint AS score
              FROM candidates c
              LEFT JOIN engagements e ON e.post_id = c.id
              LEFT JOIN mutual m ON m.id = c.author_id
              LEFT JOIN connections cn ON cn.author_id = c.author_id
            )
            SELECT * FROM scored";
            if ($beforeScore !== null && $beforePostedAt !== null) {
                $sql .= ' WHERE (score, posted_at, id) < (:before_score::bigint, :before_posted_at::timestamptz, :before_id)';
                $params += ['before_score' => $beforeScore, 'before_posted_at' => Time::toDb($beforePostedAt), 'before_id' => $beforeId];
            }
            $sql .= ' ORDER BY score DESC, posted_at DESC, id DESC LIMIT :limit';
        } else {
            $sql .= ' SELECT *, 0 AS score FROM candidates';
            if ($beforePostedAt !== null) {
                $sql .= ' WHERE (posted_at, id) < (:before_posted_at::timestamptz, :before_id)';
                $params += ['before_posted_at' => Time::toDb($beforePostedAt), 'before_id' => $beforeId];
            }
            $sql .= ' ORDER BY posted_at DESC, id DESC LIMIT :limit';
        }

        return array_map(
            static fn (array $row): array => [Post::fromRow($row), User::fromRow($row, 'author.'), (int) $row['score']],
            $this->db->fetchAll($sql, $params),
        );
    }

    /**
     * 削除されていない投稿を、投稿者と、閲覧判定に必要な関係だけを含む閲覧者と一緒に返す。
     * 閲覧者の確認、今日投稿済みか、ブロック・友達・共通の友達の有無まで 1 回の SQL で調べる。
     * 閲覧者が退会済みなら閲覧者は null
     *
     * @return array{Post, User, ?ViewerContext}|null
     */
    public function findForViewer(string $viewerId, string $postId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . Post::COLUMNS . ', ' . User::columnsAs('u', 'author') . ', ' . User::columnsAs('v', 'viewer') . ",
               EXISTS(
                 SELECT 1 FROM posts vp
                 JOIN posting_windows posted ON posted.id = vp.posting_window_id
                 JOIN posting_windows cur ON cur.region = v.region AND cur.ended_at IS NULL
                                         AND cur.local_date = posted.local_date
                 WHERE vp.author_id = v.id AND vp.deleted_at IS NULL
               ) AS viewer_posted_today,
               EXISTS(
                 SELECT 1 FROM block_relations
                 WHERE (blocker_id = :viewer_id AND blocked_id = p.author_id)
                    OR (blocker_id = p.author_id AND blocked_id = :viewer_id)
               ) AS viewer_blocked,
               EXISTS(
                 SELECT 1 FROM friendships
                 WHERE (user_low_id = :viewer_id AND user_high_id = p.author_id)
                    OR (user_low_id = p.author_id AND user_high_id = :viewer_id)
               ) AS viewer_friend,
               CASE WHEN p.visibility = 'friends_of_friends' THEN EXISTS(
                 SELECT 1 FROM friendships viewer_low
                 JOIN friendships author_low
                   ON viewer_low.user_high_id = author_low.user_high_id
                 WHERE viewer_low.user_low_id = :viewer_id AND author_low.user_low_id = p.author_id
                 UNION ALL
                 SELECT 1 FROM friendships viewer_low
                 JOIN friendships author_high
                   ON viewer_low.user_high_id = author_high.user_low_id
                 WHERE viewer_low.user_low_id = :viewer_id AND author_high.user_high_id = p.author_id
                 UNION ALL
                 SELECT 1 FROM friendships viewer_high
                 JOIN friendships author_low
                   ON viewer_high.user_low_id = author_low.user_high_id
                 WHERE viewer_high.user_high_id = :viewer_id AND author_low.user_low_id = p.author_id
                 UNION ALL
                 SELECT 1 FROM friendships viewer_high
                 JOIN friendships author_high
                   ON viewer_high.user_low_id = author_high.user_low_id
                 WHERE viewer_high.user_high_id = :viewer_id AND author_high.user_high_id = p.author_id
               ) ELSE false END AS viewer_shares_friend
             FROM posts p
             JOIN posting_windows w ON w.id = p.posting_window_id
             JOIN users u ON u.id = p.author_id AND u.status = 'active'
             LEFT JOIN users v ON v.id = :viewer_id AND v.status = 'active'
             WHERE p.deleted_at IS NULL AND p.id = :post_id",
            ['viewer_id' => $viewerId, 'post_id' => $postId],
        );
        if ($row === null) {
            return null;
        }
        $post = Post::fromRow($row);
        $author = User::fromRow($row, 'author.');
        if ($row['viewer.id'] === null) {
            return [$post, $author, null];
        }
        $user = User::fromRow($row, 'viewer.');

        $hasPostedToday = (bool) $row['viewer_posted_today'];
        if ($post->authorId === $user->id || $post->windowEndedAt !== null || !$hasPostedToday) {
            return [$post, $author, new ViewerContext($user, $hasPostedToday, [], [], [], [])];
        }
        if ((bool) $row['viewer_blocked']) {
            return [$post, $author, new ViewerContext($user, $hasPostedToday, [], [], [], [$post->authorId => true])];
        }

        $friends = [];
        $friendsOfFriends = [];
        $visibility = Visibility::tryFrom($post->visibility);
        if ($visibility === Visibility::Friends || $visibility === Visibility::FriendsOfFriends) {
            if ((bool) $row['viewer_friend']) {
                $friends[$post->authorId] = true;
                if ($visibility === Visibility::FriendsOfFriends) {
                    $friendsOfFriends[$post->authorId] = true;
                }
            } elseif ($visibility === Visibility::FriendsOfFriends && (bool) $row['viewer_shares_friend']) {
                $friendsOfFriends[$post->authorId] = true;
            }
        }

        return [$post, $author, new ViewerContext($user, $hasPostedToday, $friends, $friendsOfFriends, [], [])];
    }

    public function canView(string $viewerId, string $postId): bool
    {
        $found = $this->findForViewer($viewerId, $postId);
        if ($found === null) {
            return false;
        }
        return $found[2]?->canView($found[0]) ?? false;
    }

    /**
     * @return list<string>
     */
    public function friendIds(string $userId): array
    {
        $friendships = $this->db->fetchAll(
            'SELECT user_low_id, user_high_id FROM friendships WHERE user_low_id = :user_id OR user_high_id = :user_id',
            ['user_id' => $userId],
        );
        return array_map(
            static fn (array $friendship): string => $friendship['user_low_id'] === $userId ? $friendship['user_high_id'] : $friendship['user_low_id'],
            $friendships,
        );
    }

    /**
     * ブロックしている、またはブロックされているユーザー
     *
     * @return array<string, true>
     */
    public function blockedUserIds(string $userId): array
    {
        $ids = $this->db->fetchColumn(
            'SELECT CASE WHEN blocker_id = :user_id THEN blocked_id ELSE blocker_id END
             FROM block_relations WHERE blocker_id = :user_id OR blocked_id = :user_id',
            ['user_id' => $userId],
        );
        return array_fill_keys($ids, true);
    }
}
