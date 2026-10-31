<?php

declare(strict_types=1);

namespace Isureal;

final readonly class Config
{
    public function __construct(
        public string $region = 'asia',
        public string $dbHost = '127.0.0.1',
        public string $dbPort = '5432',
        public string $dbUser = 'isureal',
        public string $dbPassword = 'isureal',
        public string $dbName = 'isureal',
        public string $logLevel = 'debug',
        public string $notificationHost = '127.0.0.1',
        public int $notificationPort = 9002,
        public int $notificationMaxConnections = 256,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            region: self::env('ISUREAL_REGION', 'asia'),
            dbHost: self::env('ISUREAL_DB_HOST', '127.0.0.1'),
            dbPort: self::env('ISUREAL_DB_PORT', '5432'),
            dbUser: self::env('ISUREAL_DB_USER', 'isureal'),
            dbPassword: self::env('ISUREAL_DB_PASSWORD', 'isureal'),
            dbName: self::env('ISUREAL_DB_NAME', 'isureal'),
            logLevel: self::env('ISUREAL_LOG_LEVEL', 'debug'),
            notificationHost: self::env('ISUREAL_NOTIFICATION_HOST', '127.0.0.1'),
            notificationPort: self::positiveIntEnv('ISUREAL_NOTIFICATION_PORT', 9002, 65535),
            notificationMaxConnections: self::positiveIntEnv('ISUREAL_NOTIFICATION_MAX_CONNECTIONS', 256, 256),
        );
    }

    public function databaseDsn(): string
    {
        // 時刻は UTC で受け取る
        return sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;options='-c TimeZone=UTC'",
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }

    private static function positiveIntEnv(string $key, int $default, int $max): int
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if (!is_int($parsed)) {
            throw new \RuntimeException("{$key} must be an integer between 1 and {$max}");
        }
        return $parsed;
    }
}
