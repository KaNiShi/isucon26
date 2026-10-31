<?php

declare(strict_types=1);

namespace Isureal\Repository;

use Isureal\Database;
use Isureal\Model\Post;
use Isureal\Model\User;
use Isureal\Model\Visibility;
use Isureal\Model\ViewerContext;

final readonly class PostRepository
{
    public function __construct(private Database $db, private UserRepository $users)
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

    public function loadViewer(string $viewerId): ?ViewerContext
    {
        $user = $this->users->find($viewerId);
        if ($user === null) {
            return null;
        }
        $hasPostedToday = $this->hasPostedToday($user->id, $user->region);
        $blocked = $this->blockedUserIds($user->id);
        $friendIds = $this->friendIds($user->id);
        if ($friendIds === []) {
            return new ViewerContext($user, $hasPostedToday, [], [], [], $blocked);
        }
        $friends = array_fill_keys($friendIds, true);
        [$in, $params] = Database::inClause('friend', $friendIds);
        $friendships = $this->db->fetchAll(
            "SELECT user_low_id, user_high_id FROM friendships WHERE user_low_id IN ({$in}) OR user_high_id IN ({$in})",
            $params,
        );
        $friendsOfFriends = [];
        $mutualFriends = [];
        foreach ($friendships as ['user_low_id' => $low, 'user_high_id' => $high]) {
            if (isset($friends[$low])) {
                $friendsOfFriends[$high] = true;
                if ($high !== $user->id) {
                    $mutualFriends[$high] = ($mutualFriends[$high] ?? 0) + 1;
                }
            }
            if (isset($friends[$high])) {
                $friendsOfFriends[$low] = true;
                if ($low !== $user->id) {
                    $mutualFriends[$low] = ($mutualFriends[$low] ?? 0) + 1;
                }
            }
        }
        return new ViewerContext($user, $hasPostedToday, $friends, $friendsOfFriends, $mutualFriends, $blocked);
    }

    /**
     * 投稿1件の閲覧判定に必要な関係だけを含む閲覧者を返す。
     */
    public function loadViewerForPost(string $viewerId, Post $post): ?ViewerContext
    {
        $user = $this->users->find($viewerId);
        if ($user === null) {
            return null;
        }

        $hasPostedToday = $this->hasPostedToday($user->id, $user->region);
        if ($post->authorId === $user->id) {
            return new ViewerContext($user, $hasPostedToday, [], [], [], []);
        }
        if ($post->windowEndedAt !== null || !$hasPostedToday) {
            return new ViewerContext($user, $hasPostedToday, [], [], [], []);
        }

        $blocked = $this->isBlocked($user->id, $post->authorId) ? [$post->authorId => true] : [];
        if ($blocked !== []) {
            return new ViewerContext($user, $hasPostedToday, [], [], [], $blocked);
        }

        $friends = [];
        $friendsOfFriends = [];
        $visibility = Visibility::tryFrom($post->visibility);
        if ($visibility === Visibility::Friends || $visibility === Visibility::FriendsOfFriends) {
            $isFriend = $this->areFriends($user->id, $post->authorId);
            if ($isFriend) {
                $friends[$post->authorId] = true;
                if ($visibility === Visibility::FriendsOfFriends) {
                    $friendsOfFriends[$post->authorId] = true;
                }
            } elseif ($visibility === Visibility::FriendsOfFriends && $this->shareFriend($user->id, $post->authorId)) {
                $friendsOfFriends[$post->authorId] = true;
            }
        }

        return new ViewerContext($user, $hasPostedToday, $friends, $friendsOfFriends, [], $blocked);
    }

    public function canView(string $viewerId, string $postId): bool
    {
        $found = $this->findWithAuthor($postId);
        if ($found === null) {
            return false;
        }
        return $this->loadViewerForPost($viewerId, $found[0])?->canView($found[0]) ?? false;
    }

    private function hasPostedToday(string $userId, string $region): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS(
               SELECT 1 FROM posts p
               JOIN posting_windows posted ON posted.id = p.posting_window_id
               JOIN posting_windows current ON current.region = :region AND current.ended_at IS NULL
                                           AND current.local_date = posted.local_date
               WHERE p.author_id = :user_id AND p.deleted_at IS NULL
             )',
            ['user_id' => $userId, 'region' => $region],
        );
    }

    private function isBlocked(string $userId, string $otherId): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS(
               SELECT 1 FROM block_relations
               WHERE (blocker_id = :user_id AND blocked_id = :other_id)
                  OR (blocker_id = :other_id AND blocked_id = :user_id)
             )',
            ['user_id' => $userId, 'other_id' => $otherId],
        );
    }

    private function areFriends(string $userId, string $otherId): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS(
               SELECT 1 FROM friendships
               WHERE (user_low_id = :user_id AND user_high_id = :other_id)
                  OR (user_low_id = :other_id AND user_high_id = :user_id)
             )',
            ['user_id' => $userId, 'other_id' => $otherId],
        );
    }

    private function shareFriend(string $userId, string $otherId): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS(
               SELECT 1 FROM friendships viewer_low
               JOIN friendships author_low
                 ON viewer_low.user_high_id = author_low.user_high_id
               WHERE viewer_low.user_low_id = :user_id AND author_low.user_low_id = :other_id
               UNION ALL
               SELECT 1 FROM friendships viewer_low
               JOIN friendships author_high
                 ON viewer_low.user_high_id = author_high.user_low_id
               WHERE viewer_low.user_low_id = :user_id AND author_high.user_high_id = :other_id
               UNION ALL
               SELECT 1 FROM friendships viewer_high
               JOIN friendships author_low
                 ON viewer_high.user_low_id = author_low.user_high_id
               WHERE viewer_high.user_high_id = :user_id AND author_low.user_low_id = :other_id
               UNION ALL
               SELECT 1 FROM friendships viewer_high
               JOIN friendships author_high
                 ON viewer_high.user_low_id = author_high.user_low_id
               WHERE viewer_high.user_high_id = :user_id AND author_high.user_high_id = :other_id
             )',
            ['user_id' => $userId, 'other_id' => $otherId],
        );
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
