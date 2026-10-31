<?php

declare(strict_types=1);

namespace Isureal\Repository;

use Isureal\Database;
use Isureal\Model\Region;

/**
 * Synchronizes changes to region membership with posting-window notifications.
 */
final class UserRegionLock
{
    private const int NAMESPACE = 0x4953524c;

    /**
     * Acquires shared membership locks before a user's region or active status changes.
     * All regions are acquired in a stable order so callers can safely lock a user row next.
     */
    public static function lockAllShared(Database $db): void
    {
        foreach ([Region::Asia, Region::Europe, Region::America] as $region) {
            self::lock($db, $region, shared: true);
        }
    }

    /**
     * Acquires a shared lock before registering a user in the given region.
     */
    public static function lockShared(Database $db, string $region): void
    {
        self::lock($db, self::region($region), shared: true);
    }

    /**
     * Acquires an exclusive lock while the posting-window notification target is selected.
     */
    public static function lockExclusive(Database $db, string $region): void
    {
        self::lock($db, self::region($region), shared: false);
    }

    private static function lock(Database $db, Region $region, bool $shared): void
    {
        $function = $shared ? 'pg_advisory_xact_lock_shared' : 'pg_advisory_xact_lock';
        $db->fetchValue(
            "SELECT {$function}(:namespace::integer, :region_id::integer)",
            ['namespace' => self::NAMESPACE, 'region_id' => self::regionId($region)],
        );
    }

    private static function region(string $region): Region
    {
        return Region::tryFrom($region) ?? throw new \InvalidArgumentException('invalid region');
    }

    private static function regionId(Region $region): int
    {
        return match ($region) {
            Region::Asia => 1,
            Region::Europe => 2,
            Region::America => 3,
        };
    }
}
