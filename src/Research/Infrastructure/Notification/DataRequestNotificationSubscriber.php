<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Research\Application\Event\DataRequestChanged;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Entity\DataRequestEvent;
use App\Research\Domain\Entity\DataRequestRound;
use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;
use App\User\Application\Contract\AdminNotificationRecipientResolverInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Notifications of data extract requests (ADR 045), published after the commit from the stored timeline events and
 * comments, in seven groups (the parameter `kind` names the event within a group):
 *
 * - `request_submitted` (Board, off by default): a request waits for the formal check;
 * - `board_vote` (Board): a round opened, or after a revision the new version for members whose approval was carried
 *   over; the 48-hour reminder comes from DataRequestReminders;
 * - `board_status` (Board): a veto stopped a request, the applicant withdrew it;
 * - `commented`: a new comment, to Board members and administrators who took part (voted or commented) and to the
 *   applicant for comments of others they may read (public and private, `own` = 1);
 * - `decided` (applicant): returned, revision requested, approved, rejected, stopped, expired;
 * - `export` (recipient): release created, export provided; the reminders come from DataRequestReminders;
 * - `admin` (administrators): approved, vetoed, export failed or blocked.
 *
 * The deduplication keys name the request and the event or comment, so a repeated announcement notifies once. The
 * notifications store the request number and title only, never comment texts or reasons.
 *
 * @psalm-suppress UnusedClass Registered as an event listener.
 */
final readonly class DataRequestNotificationSubscriber
{
    private const string SHOW_ROUTE = 'app_data_requests_show';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private AdminNotificationRecipientResolverInterface $adminRecipients,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    #[AsEventListener]
    public function onChanged(DataRequestChanged $changed): void
    {
        $request = $this->entityManager->find(DataRequest::class, $changed->requestId);
        if (!$request instanceof DataRequest) {
            return;
        }

        foreach ($changed->eventIds as $eventId) {
            $event = $this->entityManager->find(DataRequestEvent::class, $eventId);
            if ($event instanceof DataRequestEvent) {
                $this->onEvent($request, $event);
            }
        }

        foreach ($changed->commentIds as $commentId) {
            $comment = $this->entityManager->find(DataRequestComment::class, $commentId);
            if ($comment instanceof DataRequestComment) {
                $this->onComment($request, $comment);
            }
        }
    }

    private function onEvent(DataRequest $request, DataRequestEvent $event): void
    {
        $key = (string) $event->getId();
        $details = $event->getDetails();
        $actorId = $event->getActor()?->getId();

        switch ($event->getType()) {
            case TimelineEventType::Submitted:
                if (DataRequestStatus::Submitted === $request->getStatus()) {
                    $this->publish(RequestSubmittedNotification::TYPE, $this->without($this->boardMemberIds($request), $actorId), $request, $key);
                }

                break;
            case TimelineEventType::Returned:
                $this->toApplicant($request, 'returned', $key);

                break;
            case TimelineEventType::RoundOpened:
                $this->onRoundOpened($request, (int) ($details['round'] ?? 0), $key);

                break;
            case TimelineEventType::RoundClosed:
                $this->onRoundClosed($request, (string) ($details['outcome'] ?? ''), $actorId, $key);

                break;
            case TimelineEventType::VetoResolved:
                $resolution = $details['resolution'] ?? null;
                if ('close' === $resolution || 'revision' === $resolution) {
                    $this->toApplicant($request, 'close' === $resolution ? 'rejected' : 'revision_requested', $key);
                }

                break;
            case TimelineEventType::Expired:
                $this->toApplicant($request, 'expired', $key);

                break;
            case TimelineEventType::Withdrawn:
                $this->publish(BoardStatusNotification::TYPE, $this->without($this->boardMemberIds($request), $actorId), $request, $key, ['kind' => 'withdrawn']);

                break;
            case TimelineEventType::Released:
                $this->publish(ExportNotification::TYPE, $this->applicantIds($request), $request, $key, ['kind' => 'released', 'release' => (int) ($details['release'] ?? 0)]);

                break;
            case TimelineEventType::ExportProvided:
                $this->publish(ExportNotification::TYPE, $this->applicantIds($request), $request, $key, ['kind' => 'provided', 'release' => (int) ($details['release'] ?? 0)]);

                break;
            case TimelineEventType::ExportBlocked:
            case TimelineEventType::ExportFailed:
                $this->publish(AdminNotification::TYPE, $this->adminRecipients->recipientIds(), $request, $key, [
                    'kind' => TimelineEventType::ExportBlocked === $event->getType() ? 'blocked' : 'failed',
                    'release' => (int) ($details['release'] ?? 0),
                ]);

                break;
            default:
                break;
        }
    }

    private function onRoundOpened(DataRequest $request, int $number, string $key): void
    {
        $round = $this->round($request, $number);
        if (!$round instanceof DataRequestRound) {
            return;
        }

        $carriedOver = [];
        if (RoundKind::AfterRevision === $round->getKind()) {
            foreach ($round->getVotes() as $vote) {
                $voterId = $vote->getVoter()?->getId();
                if ($vote->isCarriedOver() && null !== $voterId) {
                    $carriedOver[] = $voterId;
                }
            }
        }

        $parameters = ['round' => $round->getNumber(), 'version' => $round->getVersion()->getNumber()];
        $this->publish(BoardVoteNotification::TYPE, $carriedOver, $request, $key, ['kind' => 'revision'] + $parameters);
        $this->publish(BoardVoteNotification::TYPE, array_values(array_diff($round->getEligibleMemberIds(), $carriedOver)), $request, $key, ['kind' => 'opened'] + $parameters);
    }

    private function onRoundClosed(DataRequest $request, string $outcome, ?int $actorId, string $key): void
    {
        switch ($outcome) {
            case 'approved':
                $this->toApplicant($request, 'approved', $key);
                $this->publish(AdminNotification::TYPE, $this->adminRecipients->recipientIds(), $request, $key, ['kind' => 'approved']);

                break;
            case 'rejected':
            case 'quorum_missed':
                $this->toApplicant($request, 'rejected', $key);

                break;
            case 'revision_requested':
                $this->toApplicant($request, 'revision_requested', $key);

                break;
            case 'vetoed':
                $this->toApplicant($request, 'stalled', $key);
                $vetoer = $this->lastVoterOf($request) ?? $actorId;
                $board = $this->boardMemberIds($request);
                $this->publish(BoardStatusNotification::TYPE, $this->without($board, $vetoer), $request, $key, ['kind' => 'vetoed']);
                // Administrators who are also Board members already heard of it.
                $this->publish(AdminNotification::TYPE, $this->without(array_values(array_diff($this->adminRecipients->recipientIds(), $board)), $vetoer), $request, $key, ['kind' => 'vetoed']);

                break;
            default:
                break;
        }
    }

    private function onComment(DataRequest $request, DataRequestComment $comment): void
    {
        $authorId = $comment->getAuthor()?->getId();
        $key = 'comment:'.(int) $comment->getId();

        if (Visibility::Internal !== $comment->getVisibility()) {
            $this->publish(CommentedNotification::TYPE, $this->without($this->applicantIds($request), $authorId), $request, $key, ['own' => 1]);
        }

        $this->publish(CommentedNotification::TYPE, $this->without($this->participantIds($request), $authorId), $request, $key, ['own' => 0]);
    }

    private function toApplicant(DataRequest $request, string $outcome, string $key): void
    {
        $this->publish(DecidedNotification::TYPE, $this->applicantIds($request), $request, $key, ['outcome' => $outcome]);
    }

    /**
     * @param list<int>                 $recipientIds
     * @param array<string, int|string> $parameters
     */
    private function publish(string $type, array $recipientIds, DataRequest $request, string $key, array $parameters = []): void
    {
        $recipientIds = array_values(array_unique($recipientIds));
        if ([] === $recipientIds) {
            return;
        }

        $this->publisher->publish(new NotificationRequest(
            type: $type,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%s:%s', $type, $request->getPublicIdString(), $key),
            parameters: ['number' => (int) $request->getId(), 'title' => $request->getCurrentVersion()->getTitle()] + $parameters,
            action: new NotificationAction(self::SHOW_ROUTE, ['publicId' => $request->getPublicIdString()]),
            metadata: ['reference' => \sprintf('data_request:%d', (int) $request->getId())],
        ));
    }

    /** @return list<int> */
    private function applicantIds(DataRequest $request): array
    {
        $id = $request->getRequester()?->getId();

        return null === $id ? [] : [$id];
    }

    /**
     * Board members who may take part: not the applicant, not excluded.
     *
     * @return list<int>
     */
    private function boardMemberIds(DataRequest $request): array
    {
        $ids = [];
        foreach ($this->users->findEnabledVerifiedUsersWithRoles([UserRole::BOARD_MEMBER]) as $member) {
            $id = $member->getId();
            if (null !== $id && !$request->isRequestedBy($member) && !$request->isExcluded($member)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Board members and administrators who voted in a round or commented, without the applicant and excluded members.
     *
     * @return list<int>
     */
    private function participantIds(DataRequest $request): array
    {
        $people = [];
        foreach ($request->getRounds() as $round) {
            foreach ($round->getVotes() as $vote) {
                $people[] = $vote->getVoter();
            }
        }

        foreach ($request->getComments() as $comment) {
            $people[] = $comment->getAuthor();
        }

        $ids = [];
        foreach ($people as $person) {
            $id = $person?->getId();
            if ($person instanceof User && null !== $id && $person->isEnabled() && !$request->isRequestedBy($person) && !$request->isExcluded($person)
                && [] !== array_intersect([UserRole::BOARD_MEMBER, UserRole::ADMIN], $person->getRoles())) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function round(DataRequest $request, int $number): ?DataRequestRound
    {
        foreach ($request->getRounds() as $round) {
            if ($round->getNumber() === $number) {
                return $round;
            }
        }

        return null;
    }

    /** The member whose veto ended the last round; they do not need to hear of it. */
    private function lastVoterOf(DataRequest $request): ?int
    {
        $last = null;
        foreach ($request->getLastRound()?->getVotes() ?? [] as $vote) {
            if (null === $last || $vote->getUpdatedAt() >= $last->getUpdatedAt()) {
                $last = $vote;
            }
        }

        return $last?->getVoter()?->getId();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function without(array $ids, ?int $id): array
    {
        return array_values(array_filter($ids, static fn (int $candidate): bool => $candidate !== $id));
    }
}
