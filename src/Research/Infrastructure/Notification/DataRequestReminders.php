<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationAction;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestExportRun;
use App\Research\Domain\Entity\DataRequestRelease;
use App\Research\Domain\Entity\DataRequestRound;
use App\Research\Domain\Enum\ExportRunStatus;
use App\Research\Domain\Enum\ReleaseStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reminders of data extract requests before a deadline (ADR 045), sent by the scheduler job
 * `research_data_request_deadlines` every 15 minutes:
 *
 * - eligible Board members who have not voted, 48 hours before the round ends;
 * - the recipient of a release without an export, 14 days before it ends;
 * - the recipient of an export, 24 hours before its download period ends.
 *
 * The deduplication key names the round, release or run, so each reminder goes out once per person.
 */
final readonly class DataRequestReminders
{
    public const string VOTE_BEFORE = '+48 hours';
    public const string RELEASE_BEFORE = '+14 days';
    public const string DOWNLOAD_BEFORE = '+24 hours';
    private const string SHOW_ROUTE = 'app_data_requests_show';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationPublisherInterface $publisher,
    ) {
    }

    /** Sends what is due and returns how many reminders went out (recipients summed). */
    public function send(\DateTimeImmutable $now): int
    {
        $sent = 0;

        /** @var list<DataRequestRound> $rounds */
        $rounds = $this->entityManager->createQueryBuilder()
            ->select('round')->from(DataRequestRound::class, 'round')
            ->where('round.closedAt IS NULL')->andWhere('round.deadline > :now')->andWhere('round.deadline <= :until')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('until', $now->modify(self::VOTE_BEFORE), Types::DATETIME_IMMUTABLE)
            ->getQuery()->getResult();
        foreach ($rounds as $round) {
            $voted = [];
            foreach ($round->getVotes() as $vote) {
                $voted[] = $vote->getVoter()?->getId();
            }

            $recipients = array_values(array_diff($round->getEligibleMemberIds(), $voted));
            $sent += $this->publish(BoardVoteNotification::TYPE, $recipients, $round->getRequest(), 'round:'.(int) $round->getId(), ['kind' => 'reminder', 'round' => $round->getNumber()]);
        }

        /** @var list<DataRequestRelease> $releases */
        $releases = $this->entityManager->createQueryBuilder()
            ->select('release')->from(DataRequestRelease::class, 'release')
            ->where('release.status = :active')->andWhere('release.validUntil > :now')->andWhere('release.validUntil <= :until')
            ->setParameter('active', ReleaseStatus::Active->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('until', $now->modify(self::RELEASE_BEFORE), Types::DATETIME_IMMUTABLE)
            ->getQuery()->getResult();
        foreach ($releases as $release) {
            $sent += $this->publish(ExportNotification::TYPE, $this->recipient($release), $release->getRequest(), 'release:'.(int) $release->getId(), ['kind' => 'release_expiring', 'release' => $release->getNumber()]);
        }

        /** @var list<DataRequestExportRun> $runs */
        $runs = $this->entityManager->createQueryBuilder()
            ->select('run')->from(DataRequestExportRun::class, 'run')
            ->where('run.status = :available')->andWhere('run.availableUntil > :now')->andWhere('run.availableUntil <= :until')
            ->setParameter('available', ExportRunStatus::Available->value)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('until', $now->modify(self::DOWNLOAD_BEFORE), Types::DATETIME_IMMUTABLE)
            ->getQuery()->getResult();
        foreach ($runs as $run) {
            $release = $run->getRelease();
            $sent += $this->publish(ExportNotification::TYPE, $this->recipient($release), $release->getRequest(), 'run:'.(int) $run->getId(), ['kind' => 'download_expiring', 'release' => $release->getNumber()]);
        }

        return $sent;
    }

    /** @return list<int> */
    private function recipient(DataRequestRelease $release): array
    {
        $id = $release->getRecipient()?->getId();

        return null === $id ? [] : [$id];
    }

    /**
     * @param list<int>                 $recipientIds
     * @param array<string, int|string> $parameters
     */
    private function publish(string $type, array $recipientIds, DataRequest $request, string $key, array $parameters): int
    {
        if ([] === $recipientIds) {
            return 0;
        }

        $this->publisher->publish(new NotificationRequest(
            type: $type,
            recipientUserIds: $recipientIds,
            deduplicationKey: \sprintf('%s:%s:%s', $type, $request->getPublicIdString(), $key),
            parameters: ['number' => (int) $request->getId(), 'title' => $request->getCurrentVersion()->getTitle()] + $parameters,
            action: new NotificationAction(self::SHOW_ROUTE, ['publicId' => $request->getPublicIdString()]),
            metadata: ['reference' => \sprintf('data_request:%d', (int) $request->getId())],
        ));

        return \count($recipientIds);
    }
}
