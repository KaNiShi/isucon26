<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Database;
use Isureal\Http\ApiError;
use Isureal\Http\Input;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Ids;
use Isureal\Model\FriendRequest;
use Isureal\Repository\PostRepository;
use Isureal\Repository\RelationshipRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class FriendRequestHandler
{
    public function __construct(
        private Database $db,
        private PostRepository $posts,
        private RelationshipRepository $relationships,
    ) {
    }

    /**
     * POST /api/friend-requests
     */
    public function create(Request $request, Response $response): Response
    {
        $recipientId = Input::json($request)->string('recipientId');
        $actor = SessionMiddleware::session($request)->userId;
        $friendRequest = $this->db->transaction(function (Database $db) use ($actor, $recipientId): FriendRequest {
            $this->relationships->lockPair($actor, $recipientId);
            [$low, $high] = Ids::ordered($actor, $recipientId);
            $state = $db->fetchOne(
                "SELECT EXISTS(SELECT 1 FROM block_relations WHERE (blocker_id = :actor AND blocked_id = :recipient) OR (blocker_id = :recipient AND blocked_id = :actor)) AS blocked,
                        EXISTS(SELECT 1 FROM friendships WHERE user_low_id = :low AND user_high_id = :high) AS friends,
                        (SELECT id || ',' || requester_id FROM friend_requests WHERE status = 'pending' AND
                          ((requester_id = :actor AND recipient_id = :recipient) OR (requester_id = :recipient AND recipient_id = :actor)) LIMIT 1) AS pending",
                ['actor' => $actor, 'recipient' => $recipientId, 'low' => $low, 'high' => $high],
            ) ?? throw new \RuntimeException('sql: no rows in result set');
            if ($state['blocked'] || $state['friends']) {
                throw new ApiError(409, 'relationship_conflict');
            }
            $columns = FriendRequest::COLUMNS;
            if ($state['pending'] !== null) {
                [$existingId, $requesterId] = explode(',', (string) $state['pending'], 2);
                if ($requesterId === $actor) {
                    throw new ApiError(409, 'relationship_conflict');
                }
                $row = $db->fetchOne(
                    "WITH upd AS (
                         UPDATE friend_requests SET status = 'accepted', updated_at = clock_timestamp() WHERE id = :id RETURNING {$columns}
                     ),
                     ins AS (
                         INSERT INTO friendships (id, user_low_id, user_high_id, source_request_id)
                         VALUES (:friendship_id, :low, :high, :id) RETURNING 1
                     )
                     SELECT {$columns} FROM upd",
                    ['id' => $existingId, 'friendship_id' => Ids::generate(), 'low' => $low, 'high' => $high],
                );
            } else {
                $row = $db->fetchOne(
                    "INSERT INTO friend_requests (id, requester_id, recipient_id, status, created_at, updated_at)
                     VALUES (:id, :requester_id, :recipient_id, 'pending', clock_timestamp(), clock_timestamp())
                     RETURNING {$columns}",
                    ['id' => Ids::generate(), 'requester_id' => $actor, 'recipient_id' => $recipientId],
                );
            }
            return FriendRequest::fromRow($row ?? throw new \RuntimeException('sql: no rows in result set'));
        });
        return Json::respond($response, $friendRequest->toResponse(), 201);
    }

    /**
     * POST /api/friend-requests/{requestId}/{action}
     *
     * @param array<string, string> $args
     */
    public function transition(Request $request, Response $response, array $args): Response
    {
        $status = match ($args['action']) {
            'accept' => 'accepted',
            'reject' => 'rejected',
            'cancel' => 'cancelled',
            default => throw new ApiError(403, 'relationship_action_forbidden'),
        };
        $actor = SessionMiddleware::session($request)->userId;
        $friendRequest = $this->relationships->findFriendRequest($args['requestId']) ?? throw new ApiError(404, 'relationship_not_found');
        $allowedActor = $status === 'cancelled' ? $friendRequest->requesterId : $friendRequest->recipientId;
        if ($actor !== $allowedActor) {
            throw new ApiError(403, 'relationship_action_forbidden');
        }
        $friendRequest = $this->db->transaction(function (Database $db) use ($friendRequest, $status): FriendRequest {
            $this->relationships->lockPair($friendRequest->requesterId, $friendRequest->recipientId);
            $columns = FriendRequest::COLUMNS;
            $current = $db->fetchOne(
                "SELECT {$columns},
                        EXISTS(SELECT 1 FROM block_relations WHERE (blocker_id = :requester AND blocked_id = :recipient) OR (blocker_id = :recipient AND blocked_id = :requester)) AS blocked
                 FROM friend_requests WHERE id = :id",
                ['id' => $friendRequest->id, 'requester' => $friendRequest->requesterId, 'recipient' => $friendRequest->recipientId],
            ) ?? throw new \RuntimeException('sql: no rows in result set');
            if ($current['status'] !== 'pending') {
                throw new ApiError(403, 'relationship_action_forbidden');
            }
            if ($status === 'accepted') {
                if ($current['blocked']) {
                    throw new ApiError(409, 'relationship_conflict');
                }
                [$low, $high] = Ids::ordered($friendRequest->requesterId, $friendRequest->recipientId);
                $row = $db->fetchOne(
                    "WITH upd AS (
                         UPDATE friend_requests SET status = :status, updated_at = clock_timestamp() WHERE id = :id RETURNING {$columns}
                     ),
                     ins AS (
                         INSERT INTO friendships (id, user_low_id, user_high_id, source_request_id)
                         VALUES (:friendship_id, :low, :high, :id) RETURNING 1
                     )
                     SELECT {$columns} FROM upd",
                    ['id' => $friendRequest->id, 'status' => $status, 'friendship_id' => Ids::generate(), 'low' => $low, 'high' => $high],
                );
            } else {
                $row = $db->fetchOne(
                    "UPDATE friend_requests SET status = :status, updated_at = clock_timestamp() WHERE id = :id RETURNING {$columns}",
                    ['id' => $friendRequest->id, 'status' => $status],
                );
            }
            return FriendRequest::fromRow($row ?? throw new \RuntimeException('sql: no rows in result set'));
        });
        return Json::respond($response, $friendRequest->toResponse());
    }
}
