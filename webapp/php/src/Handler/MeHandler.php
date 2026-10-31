<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Bytea;
use Isureal\Database;
use Isureal\Http\ApiError;
use Isureal\Http\ActionLogEntry;
use Isureal\Http\Input;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Ids;
use Isureal\Jpeg;
use Isureal\Model\Region;
use Isureal\Model\User;
use Isureal\Repository\UserRepository;
use Isureal\Repository\UserRegionLock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class MeHandler
{
    public function __construct(private Database $db, private UserRepository $users)
    {
    }

    /**
     * GET /api/me
     */
    public function show(Request $request, Response $response): Response
    {
        $session = SessionMiddleware::session($request);
        $user = $this->users->find($session->userId) ?? throw new ApiError(404, 'account_not_found');
        return Json::respond($response, $user->toMe());
    }

    /**
     * PATCH /api/me
     */
    public function update(Request $request, Response $response): Response
    {
        $session = SessionMiddleware::session($request);
        $input = Input::json($request);
        [$displayName, $bio, $region] = [$input->optionalString('displayName'), $input->optionalString('bio'), $input->optionalString('region')];
        if (
            ($displayName === null && $bio === null && $region === null)
            || ($displayName !== null && !User::validText($displayName, 1, 100))
            || ($bio !== null && !User::validText($bio, 0, 500))
            || ($region !== null && !Region::isValid($region))
        ) {
            throw new ApiError(400, 'invalid_profile');
        }
        $user = $this->db->transaction(function (Database $db) use ($session, $displayName, $bio, $region): User {
            if ($region !== null) {
                UserRegionLock::lockAllShared($db);
                $db->fetchValue('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE', ['id' => $session->userId]);
            }
            if ($displayName !== null) {
                $db->execute('UPDATE users SET display_name = :value, updated_at = clock_timestamp() WHERE id = :id', ['id' => $session->userId, 'value' => $displayName]);
            }
            if ($bio !== null) {
                $db->execute('UPDATE users SET bio = :value, updated_at = clock_timestamp() WHERE id = :id', ['id' => $session->userId, 'value' => $bio]);
            }
            if ($region !== null) {
                $db->execute('UPDATE users SET region = :value, updated_at = clock_timestamp() WHERE id = :id', ['id' => $session->userId, 'value' => $region]);
            }
            return $this->users->find($session->userId) ?? throw new ApiError(404, 'account_not_found');
        });
        return Json::respond($response, $user->toMe());
    }

    /**
     * PUT /api/me/profile-image
     */
    public function putProfileImage(Request $request, Response $response): Response
    {
        if (($request->getHeader('Content-Type')[0] ?? '') !== 'image/jpeg') {
            throw new ApiError(415, 'unsupported_media_type');
        }
        $head = Jpeg::sizeFromStream($request->getBody());
        if ($head === null) {
            ActionLogEntry::of($request)->abuse = 'invalid_image';
            throw new ApiError(400, 'invalid_profile_image');
        }
        [$width, $height] = $head;
        if ($width > 512 || $height > 512) {
            throw new ApiError(400, 'invalid_profile_image');
        }

        $data = (string) $request->getBody();
        $userId = SessionMiddleware::session($request)->userId;
        $this->db->transaction(function (Database $db) use ($userId, $data): void {
            $row = $db->fetchOne(self::LOCK_USER_WITH_PROFILE_IMAGE, ['id' => $userId])
                ?? throw new ApiError(404, 'account_not_found');
            $imageId = Ids::generate();
            $db->execute(
                "INSERT INTO image_objects (id, owner_user_id, data, media_type) VALUES (:id, :owner_user_id, :data, 'image/jpeg')",
                ['id' => $imageId, 'owner_user_id' => $userId, 'data' => new Bytea($data)],
            );
            $db->execute('UPDATE users SET profile_image_id = :image_id, updated_at = clock_timestamp() WHERE id = :id', ['id' => $userId, 'image_id' => $imageId]);
            self::deleteImage($db, $row['profile_image_id'], $row['image_created_at']);
        });
        return Json::noContent($response);
    }

    /**
     * ユーザーの行をロックし、今のプロフィール画像の ID と作成時刻を返す。
     * 作成時刻があれば、削除をその画像のパーティションだけに絞り込める
     */
    private const string LOCK_USER_WITH_PROFILE_IMAGE =
        'SELECT u.profile_image_id, i.created_at AS image_created_at
         FROM users u LEFT JOIN image_metas i ON i.id = u.profile_image_id
         WHERE u.id = :id
         FOR NO KEY UPDATE OF u';

    /**
     * 画像を消す。created_at も条件に入れて、image_objects の 1 つのパーティションだけを見るようにする
     */
    private static function deleteImage(Database $db, ?string $imageId, ?string $createdAt): void
    {
        if ($imageId === null || $createdAt === null) {
            return;
        }
        $db->execute(
            'DELETE FROM image_objects WHERE id = :id AND created_at = :created_at',
            ['id' => $imageId, 'created_at' => $createdAt],
        );
    }

    /**
     * DELETE /api/me/profile-image
     */
    public function deleteProfileImage(Request $request, Response $response): Response
    {
        $userId = SessionMiddleware::session($request)->userId;
        $this->db->transaction(function (Database $db) use ($userId): void {
            $row = $db->fetchOne(self::LOCK_USER_WITH_PROFILE_IMAGE, ['id' => $userId]);
            if ($row === null || $row['profile_image_id'] === null) {
                throw new ApiError(404, 'account_not_found');
            }
            $db->execute('UPDATE users SET profile_image_id = NULL, updated_at = clock_timestamp() WHERE id = :id', ['id' => $userId]);
            self::deleteImage($db, $row['profile_image_id'], $row['image_created_at']);
        });
        return Json::noContent($response);
    }

    /**
     * POST /api/me/account-deletion
     */
    public function deleteAccount(Request $request, Response $response): Response
    {
        $userId = SessionMiddleware::session($request)->userId;
        $this->db->transaction(function (Database $db) use ($userId): void {
            UserRegionLock::lockAllShared($db);
            if (!$this->users->lock($userId, activeOnly: true)) {
                throw new ApiError(404, 'account_not_found');
            }
            $db->execute("UPDATE users SET status = 'deleted', deleted_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = :id", ['id' => $userId]);
            $db->execute(
                "UPDATE login_sessions SET active = false, invalidated_at = clock_timestamp(), reason = 'account_deletion' WHERE user_id = :id AND active = true",
                ['id' => $userId],
            );
        });
        return Json::noContent($response);
    }
}
