<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Activity;

use App\User\Application\Activity\UserActivityBackfillImportRecord;
use App\User\Application\Activity\UserActivityBackfillReport;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Activity\UserActivityImportMilestones;
use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityBackfillContentSourceInterface;
use App\User\Application\Contract\UserActivityBackfillImportSourceInterface;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\UserActivityType;
use App\User\Infrastructure\Query\UserActivityBackfillHospitalQuery;
use App\User\Infrastructure\Repository\UserActivityRepository;
use App\User\Infrastructure\Repository\UserRepository;

final readonly class UserActivityBackfill
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by Symfony DI. */
    public function __construct(
        private UserRepository $userRepository,
        private UserActivityRepository $activityRepository,
        private UserActivityRecorderInterface $activityRecorder,
        private UserActivityBackfillImportSourceInterface $importSource,
        private UserActivityBackfillContentSourceInterface $contentSource,
        private UserActivityBackfillHospitalQuery $hospitalQuery,
    ) {
    }

    public function run(bool $apply): UserActivityBackfillReport
    {
        $report = new UserActivityBackfillReport();
        $users = $this->userRepository->findBy([], ['id' => 'ASC']);
        $imports = $this->importSource->successfulImports();

        $this->backfillJoined($users, $apply, $report);
        $this->backfillMilestones($imports, $apply, $report);
        $this->backfillPosts($apply, $report);
        $this->backfillComments($apply, $report);
        $this->backfillHospitalAssociations($apply, $report);
        $this->backfillHospitalOwners($imports, $apply, $report);

        return $report;
    }

    /**
     * @param list<User> $users
     */
    private function backfillJoined(array $users, bool $apply, UserActivityBackfillReport $report): void
    {
        foreach ($users as $user) {
            $userId = $user->getId();
            if (null === $userId) {
                continue;
            }

            ++$report->inspected;
            $this->record(
                $apply,
                $report,
                'joined',
                new UserActivityWrite(
                    userId: $userId,
                    type: UserActivityType::JOINED,
                    occurredAt: $user->getCreatedAt(),
                    deduplicationKey: UserActivityDeduplicationKey::joined($userId),
                ),
            );
        }
    }

    /**
     * @param list<UserActivityBackfillImportRecord> $imports
     */
    private function backfillMilestones(array $imports, bool $apply, UserActivityBackfillReport $report): void
    {
        $byUser = [];
        foreach ($imports as $import) {
            $byUser[$import->userId][] = $import;
        }

        foreach ($byUser as $userId => $userImports) {
            $count = \count($userImports);
            foreach (UserActivityImportMilestones::RANKS as $rank) {
                if ($rank > $count) {
                    break;
                }

                $import = $userImports[$rank - 1];
                $type = 1 === $rank ? UserActivityType::FIRST_IMPORT : UserActivityType::IMPORT_MILESTONE;
                $this->record(
                    $apply,
                    $report,
                    'milestones',
                    new UserActivityWrite(
                        userId: $userId,
                        type: $type,
                        occurredAt: $import->createdAt,
                        deduplicationKey: UserActivityDeduplicationKey::importMilestone($userId, $rank),
                        metadata: [
                            'hospitalPublicId' => $import->hospitalPublicId,
                            'hospitalName' => $import->hospitalName,
                            'milestone' => $rank,
                        ],
                    ),
                );
            }
        }
    }

    private function backfillPosts(bool $apply, UserActivityBackfillReport $report): void
    {
        foreach ($this->contentSource->publishedPosts() as $post) {
            $this->record(
                $apply,
                $report,
                'posts',
                new UserActivityWrite(
                    userId: $post->userId,
                    type: UserActivityType::POST_PUBLISHED,
                    occurredAt: $post->publishedAt,
                    deduplicationKey: UserActivityDeduplicationKey::postPublished($post->userId, $post->postId),
                    metadata: [
                        'postId' => $post->postId,
                        'title' => $post->title,
                        'slug' => $post->slug,
                    ],
                ),
            );
        }
    }

    private function backfillComments(bool $apply, UserActivityBackfillReport $report): void
    {
        foreach ($this->contentSource->commentsOnPublishedPosts() as $comment) {
            $this->record(
                $apply,
                $report,
                'comments',
                new UserActivityWrite(
                    userId: $comment->userId,
                    type: UserActivityType::COMMENT_CREATED,
                    occurredAt: $comment->createdAt,
                    deduplicationKey: UserActivityDeduplicationKey::commentCreated($comment->userId, $comment->commentId),
                    metadata: [
                        'commentId' => $comment->commentId,
                        'postTitle' => $comment->postTitle,
                        'postSlug' => $comment->postSlug,
                        'excerpt' => $comment->excerpt,
                    ],
                ),
            );
        }
    }

    private function backfillHospitalAssociations(bool $apply, UserActivityBackfillReport $report): void
    {
        foreach ($this->hospitalQuery->accessGrants() as $grant) {
            $this->record(
                $apply,
                $report,
                'hospitalAssociated',
                new UserActivityWrite(
                    userId: $grant['userId'],
                    type: UserActivityType::HOSPITAL_ASSOCIATED,
                    occurredAt: $grant['createdAt'],
                    deduplicationKey: UserActivityDeduplicationKey::hospitalAssociated(
                        $grant['userId'],
                        $grant['hospitalId'],
                        $grant['grantId'],
                    ),
                    metadata: [
                        'hospitalPublicId' => $grant['hospitalPublicId'],
                        'hospitalName' => $grant['hospitalName'],
                    ],
                ),
            );
        }
    }

    /**
     * @param list<UserActivityBackfillImportRecord> $imports
     */
    private function backfillHospitalOwners(array $imports, bool $apply, UserActivityBackfillReport $report): void
    {
        $byHospital = [];
        foreach ($imports as $import) {
            $byHospital[$import->hospitalId][] = $import;
        }

        $grantUserIdsByHospital = [];
        foreach ($this->hospitalQuery->accessGrants() as $grant) {
            $grantUserIdsByHospital[$grant['hospitalId']][$grant['userId']] = true;
        }

        $currentOwners = $this->hospitalQuery->ownerUserIdsByHospital();
        $hospitalsWithOwnerActivity = [];

        foreach ($byHospital as $hospitalId => $hospitalImports) {
            $segments = $this->importerSegments($hospitalImports);
            $grantUsers = $grantUserIdsByHospital[$hospitalId] ?? [];
            $remaining = [];
            foreach ($segments as $segment) {
                if (isset($grantUsers[$segment['userId']])) {
                    continue;
                }

                $remaining[] = $segment;
            }

            if ([] === $remaining) {
                continue;
            }

            $previousUserId = null;
            foreach ($remaining as $segment) {
                $first = $segment['first'];
                $metadata = [
                    'hospitalPublicId' => $first->hospitalPublicId,
                    'hospitalName' => $first->hospitalName,
                ];

                if (null !== $previousUserId) {
                    $this->record(
                        $apply,
                        $report,
                        'hospitalOwner',
                        new UserActivityWrite(
                            userId: $previousUserId,
                            type: UserActivityType::HOSPITAL_OWNER_REVOKED,
                            occurredAt: $first->createdAt,
                            deduplicationKey: UserActivityDeduplicationKey::hospitalOwnerRevoked(
                                $previousUserId,
                                $hospitalId,
                            ),
                            metadata: $metadata,
                        ),
                    );
                }

                $this->record(
                    $apply,
                    $report,
                    'hospitalOwner',
                    new UserActivityWrite(
                        userId: $segment['userId'],
                        type: UserActivityType::HOSPITAL_OWNER_GRANTED,
                        occurredAt: $first->createdAt,
                        deduplicationKey: UserActivityDeduplicationKey::hospitalOwnerGranted(
                            $segment['userId'],
                            $hospitalId,
                        ),
                        metadata: $metadata,
                    ),
                );

                $hospitalsWithOwnerActivity[$hospitalId][$segment['userId']] = true;
                $previousUserId = $segment['userId'];
            }
        }

        foreach ($currentOwners as $hospitalId => $ownerId) {
            if (isset($hospitalsWithOwnerActivity[$hospitalId][$ownerId])) {
                continue;
            }

            ++$report->unableToReconstruct;
        }
    }

    /**
     * @param list<UserActivityBackfillImportRecord> $imports
     *
     * @return list<array{userId: int, first: UserActivityBackfillImportRecord}>
     */
    private function importerSegments(array $imports): array
    {
        $segments = [];
        $lastUserId = null;
        foreach ($imports as $import) {
            if (null !== $lastUserId && $lastUserId === $import->userId) {
                continue;
            }

            $segments[] = [
                'userId' => $import->userId,
                'first' => $import,
            ];
            $lastUserId = $import->userId;
        }

        return $segments;
    }

    /**
     * @param 'joined'|'milestones'|'posts'|'comments'|'hospitalAssociated'|'hospitalOwner' $counter
     */
    private function record(
        bool $apply,
        UserActivityBackfillReport $report,
        string $counter,
        UserActivityWrite $write,
    ): void {
        if ($this->activityRepository->existsWithDeduplicationKey($write->deduplicationKey)) {
            ++$report->skippedExisting;

            return;
        }

        ++$report->{$counter};
        if ($apply) {
            $this->activityRecorder->record($write);
        }
    }
}
