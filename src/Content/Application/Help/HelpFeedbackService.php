<?php

declare(strict_types=1);

namespace App\Content\Application\Help;

use App\Content\Application\Event\HelpArticleCommented;
use App\Content\Domain\Entity\HelpFeedback;
use App\Content\Domain\Entity\Page;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Records whether a help article helped (#596). Callers make sure the user may see the article.
 */
final readonly class HelpFeedbackService
{
    public const int MAX_COMMENT_LENGTH = 2000;
    /** As the feedback widget: shorter comments carry no information. */
    public const int MIN_COMMENT_LENGTH = 3;

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function answerOf(User $user, Page $page): ?bool
    {
        return $this->find($user, $page)?->isHelpful();
    }

    /**
     * @throws \InvalidArgumentException when $page is not a help article
     */
    public function answer(User $user, Page $page, bool $helpful, string $locale): void
    {
        if (!$page->isHelpArticle()) {
            throw new \InvalidArgumentException('Only help articles can be rated.');
        }

        $now = $this->clock->now();
        $feedback = $this->find($user, $page);
        if ($feedback instanceof HelpFeedback) {
            $feedback->answer($helpful, $locale, $now);
        } else {
            $this->entityManager->persist(new HelpFeedback($page, $user, $helpful, $locale, $now));
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A second request of the same user answered in the meantime (double click); it wins.
        }
    }

    /**
     * Passes what was missing to the feedback inbox. Comments shorter than {@see MIN_COMMENT_LENGTH} are ignored.
     */
    public function comment(User $user, Page $page, string $title, string $url, string $locale, string $comment): void
    {
        $comment = trim(mb_substr($comment, 0, self::MAX_COMMENT_LENGTH));
        $pageId = $page->getId();
        $userId = $user->getId();
        if (mb_strlen($comment) < self::MIN_COMMENT_LENGTH || null === $pageId || null === $userId || !$page->isHelpArticle()) {
            return;
        }

        $this->eventDispatcher->dispatch(new HelpArticleCommented($pageId, $userId, $title, $url, $locale, $comment));
    }

    private function find(User $user, Page $page): ?HelpFeedback
    {
        return $this->entityManager->getRepository(HelpFeedback::class)->findOneBy(['user' => $user, 'page' => $page]);
    }
}
