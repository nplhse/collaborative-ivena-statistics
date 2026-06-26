<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

use App\Allocation\Application\Message\BackfillAllocationsForIndicationRawMessage;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Security\Voter\IndicationRawReviewVoter;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class IndicationRawReviewService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuthorizationCheckerInterface $authorizationChecker,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function proposeMatch(IndicationRaw $raw, IndicationNormalized $target, User $user): void
    {
        $this->denyUnlessGranted(IndicationRawReviewVoter::EDIT_MATCH);

        if (!$raw->getFirstMatchedBy() instanceof User) {
            $raw->setFirstMatchedBy($user);
            $raw->setFirstMatchedAt(new \DateTimeImmutable());
        }

        $raw->setTarget($target);
        $raw->setReviewStatus(IndicationRawReviewStatus::NeedsReview);
        $this->em->flush();
    }

    public function matchAndApprove(IndicationRaw $raw, IndicationNormalized $target, User $user, ?string $comment = null): void
    {
        if (!$this->authorizationChecker->isGranted(UserRole::ADMIN)) {
            throw new AccessDeniedException();
        }

        $this->denyUnlessGranted(IndicationRawReviewVoter::EDIT_MATCH);

        if (!\in_array($raw->getReviewStatus(), [IndicationRawReviewStatus::Unreviewed, IndicationRawReviewStatus::NeedsReview], true)) {
            throw new \InvalidArgumentException('Indication raw cannot be directly matched from its current status.');
        }

        $raw->setFirstMatchedBy($user);
        $raw->setFirstMatchedAt(new \DateTimeImmutable());
        $raw->setTarget($target);
        $raw->setReviewStatus(IndicationRawReviewStatus::Matched);
        $raw->setReviewedAt(new \DateTimeImmutable());
        $raw->setReviewedBy($user);
        $this->applyComment($raw, $comment);
        $this->em->flush();

        $rawId = $raw->getId();
        if (null !== $rawId) {
            $this->messageBus->dispatch(new BackfillAllocationsForIndicationRawMessage($rawId));
        }
    }

    public function approveMatch(IndicationRaw $raw, User $user, ?string $comment = null): void
    {
        $this->denyUnlessGranted(IndicationRawReviewVoter::REVIEW, $raw);

        if (IndicationRawReviewStatus::NeedsReview !== $raw->getReviewStatus()) {
            throw new \InvalidArgumentException('Indication raw is not awaiting review approval.');
        }

        if (!$raw->getTarget() instanceof IndicationNormalized) {
            throw new \InvalidArgumentException('Indication raw has no proposed target.');
        }

        $raw->setReviewStatus(IndicationRawReviewStatus::Matched);
        $raw->setReviewedAt(new \DateTimeImmutable());
        $raw->setReviewedBy($user);
        $this->applyComment($raw, $comment);
        $this->em->flush();

        $rawId = $raw->getId();
        if (null !== $rawId) {
            $this->messageBus->dispatch(new BackfillAllocationsForIndicationRawMessage($rawId));
        }
    }

    public function rejectMatch(IndicationRaw $raw, User $user, ?string $comment = null): void
    {
        $this->denyUnlessGranted(IndicationRawReviewVoter::REVIEW, $raw);

        $raw->clearMatchAssignment();
        $raw->setReviewStatus(IndicationRawReviewStatus::Unreviewed);
        $raw->setReviewedAt(new \DateTimeImmutable());
        $raw->setReviewedBy($user);
        $this->applyComment($raw, $comment);
        $this->em->flush();
    }

    public function reviewNotMatchable(IndicationRaw $raw, User $user, ?string $comment = null): void
    {
        $this->denyUnlessGranted(IndicationRawReviewVoter::REVIEW, $raw);

        $raw->clearMatchAssignment();
        $raw->setReviewStatus(IndicationRawReviewStatus::NotMatchable);
        $raw->setReviewedAt(new \DateTimeImmutable());
        $raw->setReviewedBy($user);
        $this->applyComment($raw, $comment);
        $this->em->flush();
    }

    public function reviewIgnore(IndicationRaw $raw, User $user, ?string $comment = null): void
    {
        $this->denyUnlessGranted(IndicationRawReviewVoter::REVIEW, $raw);

        $raw->clearMatchAssignment();
        $raw->setReviewStatus(IndicationRawReviewStatus::Ignored);
        $raw->setReviewedAt(new \DateTimeImmutable());
        $raw->setReviewedBy($user);
        $this->applyComment($raw, $comment);
        $this->em->flush();
    }

    public function reopenForReview(IndicationRaw $raw, User $_user, ?string $comment = null): void
    {
        $this->denyUnlessGranted(IndicationRawReviewVoter::EDIT_MATCH);

        if (!\in_array($raw->getReviewStatus(), [IndicationRawReviewStatus::Ignored, IndicationRawReviewStatus::NotMatchable], true)) {
            throw new \InvalidArgumentException('Only ignored or not matchable indications can be reopened.');
        }

        $raw->clearMatchAssignment();
        $raw->setReviewStatus(IndicationRawReviewStatus::Unreviewed);
        $raw->setReviewedAt(null);
        $raw->setReviewedBy(null);
        $this->applyComment($raw, $comment);
        $this->em->flush();
    }

    public function saveComment(IndicationRaw $raw, ?string $comment): void
    {
        if (
            !$this->authorizationChecker->isGranted(IndicationRawReviewVoter::EDIT_MATCH)
            && !$this->authorizationChecker->isGranted(IndicationRawReviewVoter::REVIEW, $raw)
        ) {
            throw new AccessDeniedException();
        }

        $raw->setReviewComment($comment);
        $this->em->flush();
    }

    private function applyComment(IndicationRaw $raw, ?string $comment): void
    {
        if (null !== $comment && '' !== trim($comment)) {
            $raw->setReviewComment($comment);
        }
    }

    private function denyUnlessGranted(string $attribute, ?IndicationRaw $subject = null): void
    {
        if (!$this->authorizationChecker->isGranted($attribute, $subject)) {
            throw new AccessDeniedException();
        }
    }
}
