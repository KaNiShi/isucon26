<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Bytea;
use Isureal\Database;
use Isureal\Http\ApiError;
use Isureal\Http\Input;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Ids;
use Isureal\Jpeg;
use Isureal\Model\Post;
use Isureal\Model\PostLocation;
use Isureal\Model\User;
use Isureal\Model\Visibility;
use Isureal\Repository\PostingWindowRepository;
use Isureal\Repository\PostRepository;
use Isureal\Repository\UserRepository;
use Isureal\Time;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class PostHandler
{
    public function __construct(
        private Database $db,
        private UserRepository $users,
        private PostRepository $posts,
        private PostingWindowRepository $windows,
    ) {
    }

    /**
     * POST /api/posts
     */
    public function create(Request $request, Response $response): Response
    {
        [$fields, $files] = Input::form($request);
        $image = $files['image'] ?? null;
        $caption = $fields['caption'] ?? '';
        $visibility = $fields['visibility'] ?? '';
        $location = new PostLocation(
            kind: $fields['location_kind'] ?? '',
            latitude: Input::formNumber($fields['latitude'] ?? null),
            longitude: Input::formNumber($fields['longitude'] ?? null),
            locality: $fields['locality'] ?? null,
        );
        if ($image === null || !User::validText($caption, 0, 1000) || Visibility::tryFrom($visibility) === null || !$location->isValid()) {
            throw new ApiError(400, 'invalid_post');
        }
        $imageData = (string) $image->getStream();
        [$width, $height] = Jpeg::size($imageData) ?? throw new ApiError(400, 'invalid_post');
        if ($width > 4096 || $height > 4096) {
            throw new ApiError(400, 'invalid_post');
        }

        $userId = SessionMiddleware::session($request)->userId;
        [$post, $user] = $this->db->transaction(function (Database $db) use ($userId, $imageData, $caption, $visibility, $location): array {
            $userRow = $db->fetchOne(
                'SELECT ' . User::columns() . " FROM users WHERE id = :id AND status = 'active' FOR NO KEY UPDATE",
                ['id' => $userId],
            ) ?? throw new \RuntimeException('sql: no rows in result set');
            $user = User::fromRow($userRow);
            $windowSql = 'WITH w AS (
                     SELECT id, local_date, starts_at, on_time_deadline FROM posting_windows WHERE region = :region AND ended_at IS NULL FOR SHARE
                 )
                 SELECT w.id, w.starts_at, w.on_time_deadline,
                        (SELECT count(*) FILTER (WHERE p.deleted_at IS NULL)
                         FROM posts p JOIN posting_windows pw ON pw.id = p.posting_window_id
                         WHERE p.author_id = :author_id AND pw.local_date = w.local_date) AS active,
                        (SELECT count(*) FILTER (WHERE p.deleted_at IS NOT NULL)
                         FROM posts p JOIN posting_windows pw ON pw.id = p.posting_window_id
                         WHERE p.author_id = :author_id AND pw.local_date = w.local_date) AS deleted
                 FROM w';
            $windowParams = ['region' => $user->region, 'author_id' => $userId];
            $window = $db->fetchOne($windowSql, $windowParams);
            $window ??= $db->fetchOne($windowSql, $windowParams);
            if ($window === null) {
                throw new ApiError(409, 'post_slot_unavailable');
            }
            $postedAt = Time::now();
            if ($window['active'] !== 0 || $window['deleted'] >= 2 || $postedAt < Time::fromDb($window['starts_at'])) {
                throw new ApiError(409, 'post_slot_unavailable');
            }
            $columns = 'ins.id, ins.author_id, ins.posting_window_id, ins.image_id, ins.caption, ins.visibility,
                        ins.location_kind, ins.latitude, ins.longitude, ins.locality, ins.posted_at';
            $row = $db->fetchOne(
                "WITH img AS (
                     INSERT INTO image_objects (id, owner_user_id, data, media_type, created_at)
                     VALUES (:image_id, :author_id, :data, 'image/jpeg', :posted_at) RETURNING 1
                 ),
                 ins AS (
                     INSERT INTO posts (id, author_id, posting_window_id, image_id, caption, visibility,
                                        location_kind, latitude, longitude, locality, posted_at)
                     VALUES (:id, :author_id, :posting_window_id, :image_id, :caption, :visibility,
                             :location_kind, :latitude, :longitude, :locality, :posted_at)
                     RETURNING id, author_id, posting_window_id, image_id, caption, visibility,
                               location_kind, latitude, longitude, locality, posted_at
                 )
                 SELECT {$columns}, w.on_time_deadline, w.ended_at AS window_ended_at
                 FROM ins JOIN posting_windows w ON w.id = ins.posting_window_id",
                [
                    'id' => Ids::generate(),
                    'image_id' => Ids::generate(),
                    'author_id' => $userId,
                    'data' => new Bytea($imageData),
                    'posting_window_id' => $window['id'],
                    'caption' => $caption,
                    'visibility' => $visibility,
                    'location_kind' => $location->kind,
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'locality' => $location->locality,
                    'posted_at' => Time::toDb($postedAt),
                ],
            ) ?? throw new \RuntimeException('sql: no rows in result set');
            return [Post::fromRow($row), $user];
        });
        return Json::respond($response, $post->toResponse($user), 201);
    }

    /**
     * GET /api/posts/me/current
     */
    public function myCurrent(Request $request, Response $response): Response
    {
        $userId = SessionMiddleware::session($request)->userId;
        $postId = $this->db->fetchValue(
            'SELECT p.id FROM posts p
             JOIN posting_windows w ON w.id = p.posting_window_id
             JOIN users u ON u.id = p.author_id
             JOIN posting_windows current ON current.region = u.region AND current.ended_at IS NULL
                                         AND current.local_date = w.local_date
             WHERE p.author_id = :author_id AND p.deleted_at IS NULL',
            ['author_id' => $userId],
        ) ?? throw new ApiError(404, 'post_not_found');
        $post = $this->posts->find($postId) ?? throw new ApiError(404, 'post_not_found');
        $user = $this->users->mustFind($userId);
        return Json::respond($response, $post->toResponse($user));
    }

    /**
     * GET /api/posts/{postId}
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        [$post, $author] = $this->posts->findWithAuthor($args['postId']) ?? throw new ApiError(404, 'post_not_found');
        $viewer = $this->posts->loadViewer(SessionMiddleware::session($request)->userId);
        if ($viewer === null || !$viewer->canView($post)) {
            throw new ApiError(404, 'post_not_found');
        }
        return Json::respond($response, $post->toResponse($author));
    }

    /**
     * PATCH /api/posts/{postId}
     *
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $input = Input::json($request);
        [$caption, $visibility] = [$input->optionalString('caption'), $input->optionalString('visibility')];
        if (
            ($caption === null && $visibility === null)
            || ($caption !== null && !User::validText($caption, 0, 1000))
            || ($visibility !== null && Visibility::tryFrom($visibility) === null)
        ) {
            throw new ApiError(400, 'invalid_post');
        }
        $userId = SessionMiddleware::session($request)->userId;
        [$post, $user] = $this->db->transaction(function (Database $db) use ($args, $userId, $caption, $visibility): array {
            $post = $this->editablePost($args['postId'], $userId);
            if ($this->windows->mustFind($post->postingWindowId)->endedAt !== null) {
                throw new ApiError(403, 'post_not_editable');
            }
            if ($caption !== null) {
                $db->execute('UPDATE posts SET caption = :caption WHERE id = :id', ['id' => $post->id, 'caption' => $caption]);
            }
            if ($visibility !== null) {
                $db->execute('UPDATE posts SET visibility = :visibility WHERE id = :id', ['id' => $post->id, 'visibility' => $visibility]);
            }
            return [$this->posts->mustFind($post->id), $this->users->mustFind($userId)];
        });
        return Json::respond($response, $post->toResponse($user));
    }

    /**
     * DELETE /api/posts/{postId}
     *
     * @param array<string, string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = SessionMiddleware::session($request)->userId;
        $this->db->transaction(function (Database $db) use ($args, $userId): void {
            $post = $this->editablePost($args['postId'], $userId);
            $window = $this->windows->mustFind($post->postingWindowId);
            $deletions = $db->fetchValue(
                'SELECT count(*) FROM posts p JOIN posting_windows w ON w.id = p.posting_window_id
                 WHERE p.author_id = :author_id AND w.local_date = :local_date AND p.deleted_at IS NOT NULL',
                ['author_id' => $userId, 'local_date' => $window->localDate],
            );
            if ($deletions >= 2) {
                throw new ApiError(409, 'post_slot_unavailable');
            }
            $db->execute('UPDATE posts SET deleted_at = clock_timestamp() WHERE id = :id', ['id' => $post->id]);
        });
        return Json::noContent($response);
    }

    /**
     * ユーザーをロックして、そのユーザーの投稿を返す。トランザクションの中で呼ぶ
     */
    private function editablePost(string $postId, string $userId): Post
    {
        $this->users->mustLock($userId);
        $post = $this->posts->find($postId) ?? throw new ApiError(404, 'post_not_found');
        if ($post->authorId !== $userId) {
            throw new ApiError(403, 'post_not_editable');
        }
        return $post;
    }
}
