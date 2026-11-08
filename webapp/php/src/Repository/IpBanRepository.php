<?php

declare(strict_types=1);

namespace Isureal\Repository;

use Isureal\Database;
use Isureal\Ids;
use Isureal\Model\LoginSession;
use Isureal\Time;

final readonly class IpBanRepository
{
    public function __construct(private Database $db)
    {
    }

    public function isBanned(string $ip): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS(SELECT 1 FROM ip_bans WHERE ip = :ip AND banned_until > clock_timestamp())',
            ['ip' => $ip],
        );
    }

    /**
     * @return array{bool, LoginSession|null}
     */
    public function isBannedWithSession(string $ip, string $token): array
    {
        $row = $this->db->fetchOne(
            "SELECT EXISTS(SELECT 1 FROM ip_bans WHERE ip = :ip AND banned_until > clock_timestamp()) AS banned,
                    s.user_id, s.token_hash, s.created_at, u.region
             FROM (SELECT 1) AS d
             LEFT JOIN login_sessions s ON s.token_hash = :token_hash AND s.active = true
             LEFT JOIN users u ON u.id = s.user_id AND u.status = 'active'",
            ['ip' => $ip, 'token_hash' => Ids::sha256($token)],
        );
        if ($row === null) {
            return [false, null];
        }
        $session = $row['region'] === null
            ? null
            : new LoginSession($row['user_id'], $row['token_hash'], Time::fromDb($row['created_at']), $row['region']);
        return [(bool) $row['banned'], $session];
    }
}
