<?php

declare(strict_types=1);

namespace App\Controllers;

use App\FeedBuilder;

/**
 * GET /feed.xml — RSS 2.0 feed.
 *
 * Reads from the cached XML (rebuilt by FeedBuilder when missing); honors
 * `If-None-Match` with an ETag so well-behaved readers cheaply poll.
 */
final class FeedController
{
    public function __construct(private readonly FeedBuilder $builder)
    {
    }

    public function show(): void
    {
        $xml = $this->builder->readOrBuild();
        $etag = '"' . sha1($xml) . '"';

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            return;
        }

        header('Content-Type: application/rss+xml; charset=utf-8');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=300');
        echo $xml;
    }
}
