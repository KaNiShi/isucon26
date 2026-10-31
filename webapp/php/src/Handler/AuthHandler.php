<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Database;
use Isureal\Http\ActionLogEntry;
use Isureal\Http\ApiError;
use Isureal\Http\Input;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Ids;
use Isureal\Model\Region;
use Isureal\Model\User;
use Isureal\Repository\SessionRepository;
use Isureal\Repository\UserRegionLock;
use Isureal\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class AuthHandler
{
    public function __construct(
        private Database $db,
        private UserRepository $users,
        private SessionRepository $sessions,
    ) {
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request, Response $response): Response
    {
        $body = Input::json($request);
        $input = ['username' => $body->string('username'), 'displayName' => $body->string('displayName'), 'region' => $body->string('region')];
        ActionLogEntry::of($request)->username = $input['username'];
        if (!User::validUsername($input['username']) || !User::validText($input['displayName'], 1, 100) || !Region::isValid($input['region'])) {
            throw new ApiError(400, 'invalid_registration');
        }
        $secretKey = Ids::generate();
        $user = $this->db->transaction(function (Database $db) use ($input, $secretKey): User {
            UserRegionLock::lockShared($db, $input['region']);
            $userId = Ids::generate();
            $inserted = $db->fetchOne(
                'INSERT INTO users (id, username, secret_key_hash, display_name, region)
                 VALUES (:id, :username, :secret_key_hash, :display_name, :region)
                 ON CONFLICT (username) DO NOTHING
                 RETURNING id',
                [
                    'id' => $userId,
                    'username' => $input['username'],
                    'secret_key_hash' => Ids::sha256($secretKey),
                    'display_name' => $input['displayName'],
                    'region' => $input['region'],
                ],
            );
            if ($inserted === null) {
                throw new ApiError(409, 'username_taken');
            }
            return $this->users->mustFind($userId);
        });
        return Json::respond($response, ['secretKey' => $secretKey, 'user' => $user->toSessionUser()], 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request, Response $response): Response
    {
        $body = Input::json($request);
        $input = ['username' => $body->string('username'), 'secretKey' => $body->string('secretKey')];
        ActionLogEntry::of($request)->username = $input['username'];
        if (!User::validUsername($input['username'])) {
            throw new ApiError(401, 'invalid_credentials');
        }
        [$token, $user] = $this->db->transaction(function (Database $db) use ($input): array {
            $row = $db->fetchOne('SELECT ' . User::columns() . ' FROM users WHERE username = :username FOR NO KEY UPDATE', ['username' => $input['username']]);
            if ($row === null) {
                throw new ApiError(401, 'invalid_credentials');
            }
            $user = User::fromRow($row);
            if ($user->status !== 'active' || $user->secretKeyHash !== Ids::sha256($input['secretKey'])) {
                throw new ApiError(401, 'invalid_credentials');
            }
            return [$this->sessions->create($user->id)->token, $user];
        });
        return Json::respond($response, ['token' => $token, 'user' => $user->toSessionUser()]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request, Response $response): Response
    {
        $session = SessionMiddleware::session($request);
        $this->sessions->invalidate($session->userId, $session->tokenHash);
        return Json::noContent($response);
    }
}
