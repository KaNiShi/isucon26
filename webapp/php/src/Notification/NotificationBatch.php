<?php

declare(strict_types=1);

namespace Isureal\Notification;

use Isureal\Database;

/**
 * 購読中の接続を1回の問い合わせで確認する。
 */
final class NotificationBatch
{
    public const string SQL = <<<'SQL'
        WITH subscribers AS (
            SELECT subscription_id, user_id, connected_at, session_region
            FROM jsonb_to_recordset(CAST(:subscriptions AS jsonb))
                AS item(subscription_id integer, user_id text, connected_at timestamptz, session_region text)
        )
        SELECT s.subscription_id,
               u.id AS active_user_id,
               u.region AS user_region,
               n.id AS notification_id,
               n.posting_window_id,
               n.region AS notification_region,
               n.created_at
        FROM subscribers s
        LEFT JOIN users u ON u.id = s.user_id AND u.status = 'active'
        LEFT JOIN LATERAL (
            SELECT notification.id, notification.posting_window_id, posting_window.region, notification.created_at
            FROM notifications notification
            JOIN posting_windows posting_window ON posting_window.id = notification.posting_window_id
            WHERE notification.user_id = s.user_id
              AND notification.created_at > s.connected_at
              AND posting_window.ended_at IS NULL
            ORDER BY notification.created_at, notification.id
            LIMIT 1
        ) n ON true
        SQL;

    /**
     * @param list<array{subscription_id: int, user_id: string, connected_at: string, session_region: string}> $subscriptions
     * @return list<array<string, mixed>>
     */
    public static function check(Database $db, array $subscriptions): array
    {
        if ($subscriptions === []) {
            return [];
        }

        return $db->fetchAll(
            self::SQL,
            ['subscriptions' => json_encode($subscriptions, JSON_THROW_ON_ERROR)],
        );
    }
}
