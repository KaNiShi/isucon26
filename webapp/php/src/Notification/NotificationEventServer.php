<?php

declare(strict_types=1);

namespace Isureal\Notification;

use Isureal\Config;
use Isureal\Database;
use Isureal\Http\ActionLog;
use Isureal\Http\ActionLogEntry;
use Isureal\Http\ApiError;
use Isureal\Http\ClientIp;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Model\LoginSession;
use Isureal\Repository\IpBanRepository;
use Isureal\Repository\SessionRepository;
use Isureal\Repository\UserRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 非同期 socket と共有 DB 接続で SSE を多重化する専用 CLI サーバー。
 */
final class NotificationEventServer
{
    private const int FALLBACK_POLL_MICROSECONDS = 500_000;
    private const int LOOP_WAIT_MICROSECONDS = 100_000;
    private const int REQUEST_TIMEOUT_SECONDS = 10;
    private const int WRITE_TIMEOUT_SECONDS = 10;
    private const int PING_INTERVAL_SECONDS = 5;
    private const int MAX_OUTPUT_BUFFER_BYTES = 65_536;
    private const int MAX_READ_BYTES = 8_192;

    /** @var resource|null */
    private $server = null;

    private ?Database $database = null;

    private float $nextDatabaseRetryAt = 0.0;

    private float $nextFallbackPollAt = 0.0;

    private bool $pollImmediately = false;

    private int $nextClientId = 1;

    /** @var array<int, array{stream: resource, state: string, accepted_at: float, write_deadline: float, next_ping: float, input: string, output: string, request: ServerRequestInterface|null, route: string|null, entry: ActionLogEntry|null, session: LoginSession|null, connected_at: string|null, status: int, logged: bool, peer: string}> */
    private array $clients = [];

    public function __construct(
        private readonly Config $config,
        private readonly ActionLog $log,
    ) {
    }

    public function run(): never
    {
        if (filter_var($this->config->notificationHost, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('ISUREAL_NOTIFICATION_HOST must be an IP address');
        }
        $host = str_contains($this->config->notificationHost, ':')
            ? '[' . $this->config->notificationHost . ']'
            : $this->config->notificationHost;
        $address = "tcp://{$host}:{$this->config->notificationPort}";
        $errno = 0;
        $error = '';
        $server = stream_socket_server(
            $address,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['socket' => ['backlog' => 4096]]),
        );
        if (!is_resource($server)) {
            throw new \RuntimeException("failed to listen on {$address} ({$errno})");
        }
        $this->server = $server;
        stream_set_blocking($server, false);
        stream_set_read_buffer($server, 0);
        error_log("[notifications] listening on {$address}");

        $this->connectDatabase(microtime(true));
        while (true) {
            $this->runLoopOnce();
        }
    }

    private function runLoopOnce(): void
    {
        $now = microtime(true);
        if ($this->database === null && $now >= $this->nextDatabaseRetryAt) {
            $this->connectDatabase($now);
        }

        if ($this->database !== null) {
            try {
                if ($this->database->consumePostingWindowNotifications()) {
                    $this->pollImmediately = true;
                }
            } catch (\Throwable) {
                $this->databaseFailed($now);
            }
        }

        $now = microtime(true);
        if ($this->database !== null && ($this->pollImmediately || $now >= $this->nextFallbackPollAt)) {
            $this->pollImmediately = false;
            $this->nextFallbackPollAt = $now + self::FALLBACK_POLL_MICROSECONDS / 1_000_000;
            $this->pollSubscribers($now);
        }

        $server = $this->server;
        if (!is_resource($server)) {
            throw new \RuntimeException('notification server socket is closed');
        }
        $read = [$server];
        $write = [];
        $readClientIds = [];
        $writeClientIds = [];
        foreach ($this->clients as $id => $client) {
            $read[] = $client['stream'];
            $readClientIds[(int) $client['stream']] = $id;
            if ($client['output'] !== '') {
                $write[] = $client['stream'];
                $writeClientIds[(int) $client['stream']] = $id;
            }
        }

        $writeStreams = $write === [] ? null : $write;
        $except = null;
        $selected = @stream_select($read, $writeStreams, $except, 0, self::LOOP_WAIT_MICROSECONDS);
        if ($selected !== false && $selected > 0) {
            foreach ($read as $stream) {
                if ($stream === $server) {
                    $this->acceptClients($server);
                    continue;
                }
                $id = $readClientIds[(int) $stream] ?? null;
                if ($id !== null && isset($this->clients[$id])) {
                    $this->readClient($id);
                }
            }
            foreach ($writeStreams ?? [] as $stream) {
                $id = $writeClientIds[(int) $stream] ?? null;
                if ($id !== null && isset($this->clients[$id])) {
                    $this->writeClient($id);
                }
            }
        }

        $this->expireClients(microtime(true));
    }

    private function connectDatabase(float $now): void
    {
        try {
            $database = Database::connect($this->config, persistent: false, timeoutSeconds: 3);
            // 長時間ぶら下がるクエリで socket loop 全体が止まらないようにする。
            $database->executeScript("SET statement_timeout TO '3s'");
            $database->listenForPostingWindowNotifications();
            $this->database = $database;
            $this->nextFallbackPollAt = 0;
            $this->pollImmediately = true;
            error_log('[notifications] shared database connection ready');
        } catch (\Throwable) {
            $this->database = null;
            $this->nextDatabaseRetryAt = $now + 1;
            error_log('[notifications] shared database connection unavailable; retrying');
        }
    }

    private function databaseFailed(float $now): void
    {
        $this->database = null;
        $this->nextDatabaseRetryAt = $now + 1;
        $this->pollImmediately = false;
        error_log('[notifications] shared database connection failed; retrying');
        foreach ($this->clients as $id => $client) {
            if ($client['state'] === 'stream') {
                $this->closeClient($id);
            }
        }
    }

    private function pollSubscribers(float $now): void
    {
        if ($this->database === null) {
            return;
        }

        $subscriptions = [];
        foreach ($this->clients as $id => $client) {
            if ($client['state'] !== 'stream' || $client['session'] === null || $client['connected_at'] === null) {
                continue;
            }
            $subscriptions[] = [
                'subscription_id' => $id,
                'user_id' => $client['session']->userId,
                'connected_at' => $client['connected_at'],
                'session_region' => $client['session']->region,
            ];
        }
        if ($subscriptions === []) {
            return;
        }

        try {
            $rows = NotificationBatch::check($this->database, $subscriptions);
        } catch (\Throwable) {
            $this->databaseFailed($now);
            return;
        }

        foreach ($rows as $row) {
            $id = (int) $row['subscription_id'];
            if (!isset($this->clients[$id]) || $this->clients[$id]['state'] !== 'stream') {
                continue;
            }
            $session = $this->clients[$id]['session'];
            if (
                $session === null
                || $row['active_user_id'] === null
                || $row['user_region'] !== $session->region
            ) {
                $this->closeClient($id);
                continue;
            }

            $event = NotificationEvent::fromRow($row);
            if ($event !== null) {
                $this->queueOutput($id, 'data: ' . Json::encode($event) . "\n\n", true);
            }
        }
    }

    /**
     * @param resource $server
     */
    private function acceptClients($server): void
    {
        for ($accepted = 0; $accepted < 64; $accepted++) {
            $peer = '';
            $stream = @stream_socket_accept($server, 0, $peer);
            if (!is_resource($stream)) {
                return;
            }
            stream_set_blocking($stream, false);
            stream_set_read_buffer($stream, 0);

            if (count($this->clients) >= $this->config->notificationMaxConnections) {
                $this->writeBusyResponse($stream);
                fclose($stream);
                continue;
            }

            $id = $this->nextClientId++;
            $now = microtime(true);
            $this->clients[$id] = [
                'stream' => $stream,
                'state' => 'request',
                'accepted_at' => $now,
                'write_deadline' => $now + self::WRITE_TIMEOUT_SECONDS,
                'next_ping' => 0.0,
                'input' => '',
                'output' => '',
                'request' => null,
                'route' => null,
                'entry' => null,
                'session' => null,
                'connected_at' => null,
                'status' => 0,
                'logged' => false,
                'peer' => $peer,
            ];
        }
    }

    private function readClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $client = $this->clients[$id];
        if ($client['state'] !== 'request') {
            $data = @fread($client['stream'], 1);
            if ($data === '' && feof($client['stream'])) {
                $this->closeClient($id);
                return;
            }
            if ($data !== false && $data !== '') {
                // SSE 接続で新しい HTTP リクエストを受け付けない。
                $this->closeClient($id);
            }
            return;
        }

        $data = @fread($client['stream'], self::MAX_READ_BYTES);
        if ($data === false || ($data === '' && feof($client['stream']))) {
            $this->closeClient($id);
            return;
        }
        if ($data === '') {
            return;
        }
        $this->clients[$id]['input'] .= $data;

        try {
            $parsed = NotificationRequestParser::parse($this->clients[$id]['input']);
        } catch (ApiError $e) {
            $this->queueHttpError($id, $e->status, $e->error);
            return;
        }
        if ($parsed !== null) {
            $this->handleRequest($id, $parsed);
        }
    }

    /**
     * @param array{method: string, target: string, version: string, has_body: bool, headers: list<array{string, string}>} $parsed
     */
    private function handleRequest(int $id, array $parsed): void
    {
        $client = $this->clients[$id];
        try {
            $requestFactory = new \Slim\Psr7\Factory\ServerRequestFactory();
            $request = $requestFactory->createServerRequest(
                $parsed['method'],
                $parsed['target'],
                ['REMOTE_ADDR' => $this->remoteAddress($client['peer'])],
            );
            foreach ($parsed['headers'] as [$name, $value]) {
                $request = $request->withAddedHeader($name, $value);
            }
            $entry = new ActionLogEntry();
            $request = $request->withAttribute(ActionLogEntry::ATTRIBUTE, $entry);
            $route = $request->getMethod() === 'GET' && $request->getUri()->getPath() === '/api/notifications'
                ? '/api/notifications'
                : null;
            $client['request'] = $request;
            $client['route'] = $route;
            $client['entry'] = $entry;
            $this->clients[$id] = $client;

            if ($this->database === null) {
                $this->queueHttpError($id, 503, 'service_unavailable');
                return;
            }
            if (new IpBanRepository($this->database)->isBanned(ClientIp::of($request))) {
                throw new ApiError(403, 'ip_banned');
            }
            if ($request->getMethod() !== 'GET') {
                throw new ApiError(405, 'Method Not Allowed');
            }
            if ($request->getUri()->getPath() !== '/api/notifications') {
                throw new ApiError(404, 'Not Found');
            }
            if ($parsed['has_body']) {
                throw ApiError::badRequest();
            }

            $session = new SessionMiddleware(
                new SessionRepository($this->database, new UserRepository($this->database)),
            )->authenticate($request);
            if ($session->region !== $this->config->region) {
                throw new ApiError(403, 'region_mismatch');
            }
            $connectedAt = $this->database->fetchValue('SELECT clock_timestamp()');
            if (!is_string($connectedAt)) {
                throw new \RuntimeException('failed to get database clock');
            }

            $client['state'] = 'stream';
            $client['session'] = $session;
            $client['connected_at'] = $connectedAt;
            $client['status'] = 200;
            $client['next_ping'] = microtime(true) + self::PING_INTERVAL_SECONDS;
            $this->clients[$id] = $client;
            $this->queueOutput(
                $id,
                "HTTP/1.1 200 OK\r\n"
                    . "Content-Type: text/event-stream\r\n"
                    . "Cache-Control: no-cache\r\n"
                    . "Connection: close\r\n"
                    . "X-Accel-Buffering: no\r\n\r\n",
            );
            $this->pollImmediately = true;
        } catch (ApiError $e) {
            $this->queueHttpError($id, $e->status, $e->error);
        } catch (\PDOException) {
            $this->queueHttpError($id, 503, 'service_unavailable');
            $this->databaseFailed(microtime(true));
        } catch (\Throwable $e) {
            error_log('[notifications] failed to handle request (' . get_class($e) . ')');
            $this->queueHttpError($id, 500, 'internal_error');
        }
    }

    private function writeClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $client = $this->clients[$id];
        $written = @fwrite($client['stream'], substr($client['output'], 0, self::MAX_READ_BYTES));
        if ($written === false) {
            $this->closeClient($id);
            return;
        }
        if ($written === 0) {
            return;
        }

        $this->clients[$id]['output'] = substr($client['output'], $written);
        $this->clients[$id]['write_deadline'] = microtime(true) + self::WRITE_TIMEOUT_SECONDS;
        if ($this->clients[$id]['output'] === '' && in_array($this->clients[$id]['state'], ['response', 'draining'], true)) {
            $this->closeClient($id);
        }
    }

    private function queueHttpError(int $id, int $status, string $error): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $body = Json::encode(['error' => $error]) . "\n";
        $reason = self::reasonPhrase($status);
        $this->clients[$id]['state'] = 'response';
        $this->clients[$id]['status'] = $status;
        $this->clients[$id]['write_deadline'] = microtime(true) + self::WRITE_TIMEOUT_SECONDS;
        $this->clients[$id]['output'] = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        if (strlen($this->clients[$id]['output']) > self::MAX_OUTPUT_BUFFER_BYTES) {
            $this->closeClient($id);
        }
    }

    private function queueOutput(int $id, string $data, bool $closeAfterWrite = false): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        if (strlen($this->clients[$id]['output']) + strlen($data) > self::MAX_OUTPUT_BUFFER_BYTES) {
            $this->closeClient($id);
            return;
        }
        $this->clients[$id]['output'] .= $data;
        $this->clients[$id]['write_deadline'] = microtime(true) + self::WRITE_TIMEOUT_SECONDS;
        if ($closeAfterWrite) {
            $this->clients[$id]['state'] = 'draining';
        }
    }

    private function expireClients(float $now): void
    {
        foreach ($this->clients as $id => $client) {
            if ($client['state'] === 'request' && $now - $client['accepted_at'] >= self::REQUEST_TIMEOUT_SECONDS) {
                $this->queueHttpError($id, 408, 'request_timeout');
                continue;
            }
            if ($client['output'] !== '' && $now >= $client['write_deadline']) {
                $this->closeClient($id);
                continue;
            }
            if ($client['state'] === 'stream' && $now >= $client['next_ping']) {
                $this->queueOutput($id, ": ping\n\n");
                if (isset($this->clients[$id])) {
                    $this->clients[$id]['next_ping'] = $now + self::PING_INTERVAL_SECONDS;
                }
            }
        }
    }

    private function closeClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $client = $this->clients[$id];
        if (!$client['logged'] && $client['request'] !== null && $client['entry'] !== null) {
            try {
                $this->log->write($client['request'], $client['route'], $client['status'] ?: 200, $client['entry']);
            } catch (\Throwable) {
                error_log('[notifications] failed to write action log');
            }
        }
        fclose($client['stream']);
        unset($this->clients[$id]);
    }

    /**
     * @param resource $stream
     */
    private function writeBusyResponse($stream): void
    {
        $body = Json::encode(['error' => 'service_unavailable']) . "\n";
        $response = "HTTP/1.1 503 Service Unavailable\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
        @fwrite($stream, $response);
    }

    private function remoteAddress(string $peer): string
    {
        if (str_starts_with($peer, '[')) {
            $closingBracket = strpos($peer, ']');
            return $closingBracket === false ? $peer : substr($peer, 1, $closingBracket - 1);
        }
        if (substr_count($peer, ':') === 1) {
            return explode(':', $peer, 2)[0];
        }
        return $peer;
    }

    private static function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            431 => 'Request Header Fields Too Large',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
