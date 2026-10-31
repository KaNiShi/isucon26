<?php

declare(strict_types=1);

namespace Isureal\Repository;

use Isureal\Database;
use Isureal\Model\RealMoji;
use Isureal\Model\User;

final readonly class RealMojiRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(string $realMojiId): ?RealMoji
    {
        $row = $this->db->fetchOne(
            'SELECT id, post_id, reactor_id, image_id, reaction_type, created_at, replaced_at, deleted_at FROM realmojis WHERE id = :id',
            ['id' => $realMojiId],
        );
        return $row === null ? null : RealMoji::fromRow($row);
    }

    public function mustFind(string $realMojiId): RealMoji
    {
        return $this->find($realMojiId) ?? throw new \RuntimeException('sql: no rows in result set');
    }

    /**
     * 置き換えも削除もされていない RealMoji をリアクションしたユーザーと一緒に返す。
     * $conditions は WHERE 句に続ける条件
     *
     * @param array<string, mixed> $params
     * @return list<array{RealMoji, User}>
     */
    public function selectCurrent(string $conditions, array $params): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . RealMoji::COLUMNS . ', ' . User::columnsAs('u', 'reactor') . "
             FROM realmojis r JOIN users u ON u.id = r.reactor_id AND u.status = 'active'
             WHERE r.replaced_at IS NULL AND r.deleted_at IS NULL {$conditions}",
            $params,
        );
        return array_map(static fn (array $row): array => [RealMoji::fromRow($row), User::fromRow($row, 'reactor.')], $rows);
    }

    /**
     * 投稿ごとに指定件数までの有効 RealMoji を、リアクションしたユーザーと一緒に返す。
     *
     * @param list<string> $postIds
     * @return list<array{RealMoji, User}>
     */
    public function selectCurrentForPosts(array $postIds, int $limitPerPost): array
    {
        if ($postIds === []) {
            return [];
        }

        [, $params] = Database::inClause('post', $postIds);
        $selectedPosts = '(' . implode('), (', array_map(static fn (string $name): string => ":{$name}", array_keys($params))) . ')';
        $params['limit_per_post'] = $limitPerPost;
        $rows = $this->db->fetchAll(
            "SELECT memory.*
             FROM (VALUES {$selectedPosts}) AS selected_posts(post_id)
             CROSS JOIN LATERAL (
                SELECT " . RealMoji::COLUMNS . ', ' . User::columnsAs('u', 'reactor') . "
                FROM realmojis r
                JOIN users u ON u.id = r.reactor_id AND u.status = 'active'
                WHERE r.replaced_at IS NULL AND r.deleted_at IS NULL AND r.post_id = selected_posts.post_id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT :limit_per_post
             ) memory
             ORDER BY memory.created_at DESC, memory.id DESC",
            $params,
        );
        return array_map(static fn (array $row): array => [RealMoji::fromRow($row), User::fromRow($row, 'reactor.')], $rows);
    }
}
