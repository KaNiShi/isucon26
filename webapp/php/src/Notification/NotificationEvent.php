<?php

declare(strict_types=1);

namespace Isureal\Notification;

use Isureal\Time;

final class NotificationEvent
{
    /**
     * @param array<string, mixed> $row
     * @return array{id: string, postingWindowId: string, region: string, createdAt: string}|null
     */
    public static function fromRow(array $row): ?array
    {
        if ($row['notification_id'] === null) {
            return null;
        }

        return [
            'id' => $row['notification_id'],
            'postingWindowId' => $row['posting_window_id'],
            'region' => $row['notification_region'],
            'createdAt' => Time::format(Time::fromDb($row['created_at'])),
        ];
    }
}
