<?php

declare(strict_types=1);

namespace Isureal\Handler;

use Isureal\Http\ApiError;
use Isureal\Http\Input;
use Isureal\Http\Json;
use Isureal\Http\Middleware\SessionMiddleware;
use Isureal\Repository\PostRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class FeedHandler
{
    private const array FEEDS = ['friends', 'friends-of-friends', 'public', 'following'];

    public function __construct(private PostRepository $posts)
    {
    }

    /**
     * GET /api/feeds/{feed}
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $feed = $args['feed'];
        $beforeScore = Input::queryInt($request, 'beforeScore');
        $beforePostedAt = Input::queryTime($request, 'beforePostedAt');
        $beforeId = Input::queryString($request, 'beforeId');
        if (!in_array($feed, self::FEEDS, true)) {
            throw new ApiError(400, 'invalid_post');
        }
        if (($beforePostedAt === null) !== ($beforeId === '')) {
            throw new ApiError(400, 'invalid_pagination');
        }
        $ranked = $feed === 'friends-of-friends';
        if ($ranked && ($beforeScore === null) !== ($beforeId === '')) {
            throw new ApiError(400, 'invalid_pagination');
        }
        $rows = $this->posts->feed(
            SessionMiddleware::session($request)->userId,
            $feed,
            $ranked ? $beforeScore : null,
            $beforePostedAt,
            $beforeId,
            51,
        );
        $items = [];
        foreach ($rows as [$post, $author, $score]) {
            $item = $post->toResponse($author);
            if ($ranked) {
                $item['score'] = $score;
            }
            $items[] = $item;
        }
        return Json::respond($response, UserHandler::page($items));
    }
}
