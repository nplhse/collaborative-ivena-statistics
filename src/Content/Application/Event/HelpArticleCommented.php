<?php

declare(strict_types=1);

namespace App\Content\Application\Event;

/**
 * A signed-in user said a help article did not help and wrote what was missing (#596). The Feedback context stores
 * the comment in its inbox, with its spam check and notifications.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the Feedback context's listener.
 */
final readonly class HelpArticleCommented
{
    public function __construct(
        public int $pageId,
        public int $userId,
        public string $articleTitle,
        public string $articleUrl,
        public string $locale,
        public string $comment,
    ) {
    }
}
