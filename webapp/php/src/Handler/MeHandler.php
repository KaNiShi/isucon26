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
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $head = $body->read(2);
            $body->rewind();
            if ($head !== "\xFF\xD8") {
                ActionLogEntry::of($request)->abuse = 'invalid_image';
                throw new ApiError(400, 'invalid_profile_image');
            }
        }
        $data = (string) $body;
        $size = Jpeg::size($data);
        if ($size === null) {
            ActionLogEntry::of($request)->abuse = 'invalid_image';
            throw new ApiError(400, 'invalid_profile_image');
        }
        [$width, $height] = $size;
        if ($width > 512 || $height > 512) {
            throw new ApiError(400, 'invalid_profile_image');
        }
        $userId = SessionMiddleware::session($request)->userId;
        $found = $this->db->fetchValue(
            "WITH old AS (SELECT profile_image_id FROM users WHERE id = :id FOR NO KEY UPDATE),
                  ins AS (
                      INSERT INTO image_objects (id, owner_user_id, data, media_type)
                      SELECT CAST(:image_id AS varchar), CAST(:id AS varchar), CAST(:data AS bytea), 'image/jpeg' FROM old
                      RETURNING 1
                  ),
                  upd AS (
                      UPDATE users SET profile_image_id = CAST(:image_id AS varchar), updated_at = clock_timestamp()
                      WHERE id = :id AND EXISTS (SELECT 1 FROM old)
                      RETURNING 1
                  ),
                  del AS (
                      DELETE FROM image_objects WHERE id = (SELECT profile_image_id FROM old)
                      RETURNING 1
                  )
             SELECT count(*) FROM old",
            ['id' => $userId, 'image_id' => Ids::generate(), 'data' => new Bytea($data)],
        );
        if ((int) $found === 0) {
            throw new ApiError(404, 'account_not_found');
        }
        return Json::noContent($response);
    }

    /**
     * DELETE /api/me/profile-image
     */
    public function deleteProfileImage(Request $request, Response $response): Response
    {
        $userId = SessionMiddleware::session($request)->userId;
        $imageId = $this->db->fetchValue(
            'WITH old AS (SELECT profile_image_id FROM users WHERE id = :id FOR NO KEY UPDATE),
                  upd AS (
                      UPDATE users SET profile_image_id = NULL, updated_at = clock_timestamp()
                      WHERE id = :id AND EXISTS (SELECT 1 FROM old WHERE profile_image_id IS NOT NULL)
                      RETURNING 1
                  ),
                  del AS (
                      DELETE FROM image_objects WHERE id = (SELECT profile_image_id FROM old)
                      RETURNING 1
                  )
             SELECT profile_image_id FROM old',
            ['id' => $userId],
        );
        if ($imageId === null) {
            throw new ApiError(404, 'account_not_found');
        }
        return Json::noContent($response);
    }

    /**
     * POST /api/me/account-deletion
     */
    public function deleteAccount(Request $request, Response $response): Response
    {
        $userId = SessionMiddleware::session($request)->userId;
        $this->db->transaction(function (Database $db) use ($userId): void {
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
