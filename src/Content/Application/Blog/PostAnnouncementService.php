<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use App\Content\Application\Message\AnnouncePostMessage;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Exception\PostCannotBeAnnouncedException;
use App\Shared\Infrastructure\Audit\AuditContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends a published blog post as an announcement (#1486): records the time on the post, so it is announced only once,
 * and hands the delivery to every enabled user to the background.
 */
final readonly class PostAnnouncementService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PostCannotBeAnnouncedException
     */
    public function announce(Post $post): void
    {
        $now = $this->clock->now();
        if (!$post->canBeAnnounced($now)) {
            throw new PostCannotBeAnnouncedException('Only a published post that was not announced before can be announced.');
        }

        $post->markAnnounced($now);
        $this->auditContext->beginIntent('content.post.announced', ['post_id' => $post->getId()]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }

        $this->messageBus->dispatch(new AnnouncePostMessage((int) $post->getId()));
    }
}
