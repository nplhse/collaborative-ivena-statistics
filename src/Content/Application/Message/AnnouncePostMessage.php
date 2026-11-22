<?php

declare(strict_types=1);

namespace App\Content\Application\Message;

/**
 * Sends a blog post as an announcement notification to every enabled user (#1486), in the background.
 */
final readonly class AnnouncePostMessage
{
    public function __construct(
        public int $postId,
    ) {
    }
}
