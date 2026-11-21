<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\EventSubscriber;

use App\Content\Application\Event\HelpArticleCommented;
use App\Feedback\Application\Contract\FeedbackSpamHistoryLookupInterface;
use App\Feedback\Application\FeedbackMessageFingerprint;
use App\Feedback\Application\FeedbackSpamChecker;
use App\Feedback\Application\RecordFeedbackHandler;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stores what a signed-in user missed in a help article (#596) in the feedback inbox, as an improvement with the
 * article as context. It passes the same spam check as the feedback widget; a rejected comment is dropped.
 */
#[AsEventListener(event: HelpArticleCommented::class, method: 'onHelpArticleCommented')]
final readonly class HelpArticleCommentedListener
{
    public const string ROUTE_NAME = 'app_page_show';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FeedbackSpamChecker $spamChecker,
        private FeedbackSpamHistoryLookupInterface $spamHistory,
        private RecordFeedbackHandler $recordFeedback,
        private ClockInterface $clock,
    ) {
    }

    public function onHelpArticleCommented(HelpArticleCommented $event): void
    {
        $user = $this->entityManager->find(User::class, $event->userId);
        if (!$user instanceof User) {
            return;
        }

        $spamCheck = $this->spamChecker->check(
            $event->comment,
            null,
            null,
            $this->clock->now()->getTimestamp(),
            true,
            true,
            $this->spamHistory->lookup(null, FeedbackMessageFingerprint::fromMessage($event->comment)),
        );
        if ($spamCheck->isRejected()) {
            return;
        }

        $this->recordFeedback->execute(
            FeedbackCategory::IMPROVEMENT,
            $event->comment,
            null,
            $user,
            $event->articleUrl,
            self::ROUTE_NAME,
            [
                'route_params' => [],
                'extra' => [
                    'source' => 'help_article',
                    'help_article_id' => $event->pageId,
                    'help_article_title' => $event->articleTitle,
                    'locale' => $event->locale,
                ],
            ],
            null,
            null,
            $spamCheck,
        );
    }
}
