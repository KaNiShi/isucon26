<?php

declare(strict_types=1);

namespace Isureal\Notification;

use Isureal\Config;
use Isureal\Database;
use Isureal\Http\ActionLog;
use Isureal\Http\ActionLogEntry;
use Isureal\Http\ApiError;
use Isureal\Http\ClientIp;
use Isureal\Http\ErrorHandler;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Model\LoginSession;
use Isureal\Repository\IpBanRepository;
use Isureal\Repository\SessionRepository;
use Isureal\Repository\UserRepository;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\ResponseEmitter;

/**
 * GET /api/notifications
 *
 * 新しい通知が届くまで接続を保持し、届いたら 1 件送って閉じる（Server-Sent Events）。
 * 待つ間は worker を占有する。DB は LISTEN して NOTIFY で起こされるのを待つので、
 * NOTIFY または最大 5 秒のタイムアウトで問い合わせる（NOTIFY は AdminHandler が通知を作るときに送る）。
 */
final class NotificationStream
{
    private const string CHANNEL = 'posting_window_opened';

    /**
     * NOTIFY を待つ最長時間。切断に気づけるよう、届かなければコメント行を送る
     */
    private const int WAIT_MILLISECONDS = 5_000;

    public function __construct(private readonly Config $config, private readonly ActionLog $log)
    {
    }

    public function handle(ServerRequestInterface $request): void
    {
        $entry = new ActionLogEntry();
        $request = $request->withAttribute(ActionLogEntry::ATTRIBUTE, $entry);
        $route = $request->getMethod() === 'GET' && $request->getUri()->getPath() === '/api/notifications' ? '/api/notifications' : null;
        try {
            [$db, $session, $since] = $this->open($request);
        } catch (ApiError $e) {
            $response = ErrorHandler::render(new Response(), $e);
            new ResponseEmitter()->emit($response);
            $this->log->write($request, $route, $response->getStatusCode(), $entry);
            return;
        } catch (\Throwable) {
            error_log('[notifications] failed to authenticate request');
            $response = Json::respond(new Response(), ['error' => 'service_unavailable'], 503);
            new ResponseEmitter()->emit($response);
            $this->log->write($request, $route, $response->getStatusCode(), $entry);
            return;
        }
        $this->stream($db, $session, $since);
        $this->log->write($request, $route, 200, $entry);
    }

    /**
     * リクエストとセッションを確かめ、通知を探し始める時刻を返す
     *
     * @return array{Database, LoginSession, string}
     */
    private function open(ServerRequestInterface $request): array
    {
        $db = Database::connect($this->config, persistent: false);
        if (new IpBanRepository($db)->isBanned(ClientIp::of($request))) {
            throw new ApiError(403, 'ip_banned');
        }
        if ($request->getMethod() !== 'GET') {
            throw new ApiError(405, 'Method Not Allowed');
        }
        if ($request->getUri()->getPath() !== '/api/notifications') {
            throw new ApiError(404, 'Not Found');
        }
        $session = new SessionMiddleware(new SessionRepository($db, new UserRepository($db)))->authenticate($request);
        if ($session->region !== $this->config->region) {
            throw new ApiError(403, 'region_mismatch');
        }
        // 作成時刻とコミットの間に接続しても、未コミットだった通知を次の poll() で拾う。
        // 同じリージョンの投稿期間の作成はロックで直列化される。
        $since = $db->fetchValue(
            "SELECT coalesce(max(created_at), '-infinity') FROM notifications WHERE user_id = :user_id",
            ['user_id' => $session->userId],
        );
        return [$db, $session, $since];
    }

    private function stream(Database $db, LoginSession $session, string $since): void
    {
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        http_response_code(200);
        // text/* に default_charset が付かないようにする
        ini_set('default_charset', '');
        header('Content-Type: text/event-stream');
        flush();

        try {
            // 接続してから LISTEN するまでの間に届いた通知は、最初の poll() で拾う
            $db->listen(self::CHANNEL);
            $shouldPoll = true;
            while (!connection_aborted()) {
                if ($shouldPoll) {
                    $notification = $this->poll($db, $session, $since);
                    if ($notification === false) {
                        return;
                    }
                    if ($notification !== null) {
                        echo 'data: ', Json::encode($notification), "\n\n";
                        flush();
                        return;
                    }
                }
                $notify = $db->waitNotify(self::WAIT_MILLISECONDS);
                if ($notify === null) {
                    echo ": ping\n\n";
                    flush();
                }
                // 他のリージョンの通知では起きても探さない。タイムアウト時は念のため探す
                $shouldPoll = $notify === null || ($notify['payload'] ?? '') === $session->region;
            }
        } catch (\Throwable) {
            error_log('[notifications] failed to poll notification');
        }
    }

    /**
     * 通知を 1 件探す。まだなければ null、通知を受けられないユーザーなら false。
     * 後方互換経路でもユーザー状態と通知を1回の問い合わせで調べる。
     *
     * @return array<string, string>|false|null
     */
    private function poll(Database $db, LoginSession $session, string $since): array|false|null
    {
        $rows = NotificationBatch::check($db, [[
            'subscription_id' => 1,
            'user_id' => $session->userId,
            'connected_at' => $since,
            'session_region' => $session->region,
        ]]);
        $row = $rows[0] ?? null;
        if ($row === null || $row['active_user_id'] === null || $row['user_region'] !== $session->region) {
            return false;
        }
        return NotificationEvent::fromRow($row);
    }
}
