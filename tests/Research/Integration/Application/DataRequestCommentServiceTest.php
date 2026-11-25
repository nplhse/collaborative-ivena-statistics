<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Application;

use App\Research\Application\DataRequest\DataRequestCommentService;
use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Application\DataRequest\DataRequestTimeline;
use App\Research\Application\DataRequest\TimelineEntry;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Enum\Visibility;
use App\Research\Domain\Enum\VoteChoice;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Who reads and writes which timeline entries of a data extract request (ADR 045).
 */
#[ResetDatabase]
final class DataRequestCommentServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testWhoReadsAndWritesWhichLevel(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $participant = UserFactory::new()->asParticipant()->create();
        $draft = $this->service()->createDraft($applicant, $this->draft());
        self::assertSame([], $this->comments()->writableLevels($applicant, $draft), 'no discussion on a draft');

        $request = $this->submitted($applicant);
        $all = [Visibility::Internal, Visibility::Private, Visibility::Public];

        self::assertSame($all, $this->comments()->readableLevels($member, $request));
        self::assertSame($all, $this->comments()->writableLevels($member, $request));
        self::assertSame($all, $this->comments()->readableLevels($admin, $request), 'administrators see everything');
        self::assertSame($all, $this->comments()->writableLevels($admin, $request));
        self::assertSame([Visibility::Private, Visibility::Public], $this->comments()->readableLevels($applicant, $request));
        self::assertSame([Visibility::Private, Visibility::Public], $this->comments()->writableLevels($applicant, $request));
        self::assertSame([Visibility::Public], $this->comments()->readableLevels($participant, $request));
        self::assertSame([], $this->comments()->writableLevels($participant, $request), 'other participants only read');
    }

    public function testAReplyNeverReachesMoreReadersThanItsComment(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-12-01 09:00:00'));
        $applicant = UserFactory::new()->asParticipant()->create();
        [$member, $other] = [UserFactory::new()->asBoardMember()->create(), UserFactory::new()->asBoardMember()->create()];
        $request = $this->submitted($applicant);

        $this->comments()->post($member, $request, 'Board only: the period seems long.', Visibility::Internal, null);
        $internal = $this->only($request);
        self::assertSame(1, $internal->getVersion()->getNumber());

        // The form sent "public"; the reply to an internal comment stays internal.
        $this->comments()->post($other, $request, 'Agreed.', Visibility::Public, $internal->getId());
        self::assertSame(Visibility::Internal, $internal->getChildren()[0]->getVisibility());

        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($applicant, $request, 'Can I see this?', Visibility::Private, $internal->getId())));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($applicant, $request, 'Board only?', Visibility::Internal, null)));
    }

    public function testTheApplicantAndTheBoardTalkPrivately(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $request = $this->submitted($applicant);

        $this->comments()->post($member, $request, 'Which hospitals exactly?', Visibility::Private, null);
        $private = $this->only($request);
        $this->comments()->post($applicant, $request, 'All participating ones.', Visibility::Private, $private->getId());
        $this->comments()->post($member, $request, 'Board note on it.', Visibility::Internal, $private->getId());

        self::assertSame([Visibility::Private, Visibility::Internal], array_map(static fn (DataRequestComment $reply): Visibility => $reply->getVisibility(), $private->getChildren()));
    }

    public function testAnExcludedMemberReadsOnlyPublicEntries(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->submitted($applicant);

        $this->review()->declareExclusion($admin, $request, $member, 'Co-author.');

        self::assertSame([Visibility::Public], $this->comments()->readableLevels($member, $request));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($member, $request, 'Internal?', Visibility::Internal, null)));
    }

    public function testEmptyCommentsAndUnknownParentsAreRefused(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->submitted($applicant);

        self::assertSame(DataRequestRefused::COMMENT_INVALID, $this->refusal(fn () => $this->comments()->post($applicant, $request, '   ', Visibility::Public, null)));
        self::assertSame(DataRequestRefused::COMMENT_INVALID, $this->refusal(fn () => $this->comments()->post($applicant, $request, str_repeat('x', 5001), Visibility::Public, null)));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($applicant, $request, 'Reply', Visibility::Public, 999999)));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post(UserFactory::new()->asParticipant()->create(), $request, 'Hello', Visibility::Public, null)));
    }

    public function testAVoteReasonIsAlwaysACommentInternalOrPrivateForARevision(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        [$a, $b, $c] = [UserFactory::new()->asBoardMember()->create(), UserFactory::new()->asBoardMember()->create(), UserFactory::new()->asBoardMember()->create()];
        $request = $this->submitted($applicant);
        $this->review()->openRound($a, $request);

        $this->review()->castVote($a, $request, VoteChoice::Approve, 'Looks sound.');
        $this->review()->castVote($b, $request, VoteChoice::Revision, 'Coarser age groups.');
        $this->review()->castVote($c, $request, VoteChoice::Approve, null);

        $comments = $request->getTopLevelComments();
        self::assertSame(['Looks sound.', 'Coarser age groups.'], array_map(static fn (DataRequestComment $comment): string => $comment->getBody(), $comments));
        self::assertSame([Visibility::Internal, Visibility::Private], array_map(static fn (DataRequestComment $comment): Visibility => $comment->getVisibility(), $comments));
        self::assertSame(VoteChoice::Revision, $comments[1]->getVote()?->getChoice());
        self::assertSame($b, $comments[1]->getAuthor());
    }

    public function testTheTimelineMixesEventsAndCommentsPerReader(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        self::mockTime(new \DateTimeImmutable('2026-12-01 09:00:00'));
        $request = $this->submitted($applicant);
        self::mockTime(new \DateTimeImmutable('2026-12-01 10:00:00'));
        $this->review()->openRound($member, $request);
        self::mockTime(new \DateTimeImmutable('2026-12-01 11:00:00'));
        $this->comments()->post($applicant, $request, 'Happy to answer questions.', Visibility::Public, null);
        self::mockTime(new \DateTimeImmutable('2026-12-01 12:00:00'));
        $this->review()->castVote($member, $request, VoteChoice::Revision, 'Please add age groups.');

        $board = DataRequestTimeline::build($request, $this->comments()->readableLevels($member, $request));
        $applicantView = DataRequestTimeline::build($request, $this->comments()->readableLevels($applicant, $request));
        $public = DataRequestTimeline::build($request, [Visibility::Public]);

        // Board: submitted, vote opened, comment, voted (internal), reason (private), round ended (revision requested).
        self::assertSame(['submitted', 'round_opened', 'comment', 'voted', 'comment', 'round_closed'], $this->kinds($board));
        self::assertSame(['submitted', 'round_opened', 'comment', 'comment', 'round_closed'], $this->kinds($applicantView), 'the applicant reads the reason for the revision, not the vote');
        self::assertSame(['submitted', 'round_opened', 'comment', 'round_closed'], $this->kinds($public));
    }

    /**
     * @param list<TimelineEntry> $entries
     *
     * @return list<string>
     */
    private function kinds(array $entries): array
    {
        return array_map(static fn (TimelineEntry $entry): string => $entry->event?->getType()->value ?? 'comment', $entries);
    }

    private function only(DataRequest $request): DataRequestComment
    {
        $comments = $request->getTopLevelComments();
        self::assertCount(1, $comments);

        return $comments[0];
    }

    /**
     * @param callable(): mixed $step
     */
    private function refusal(callable $step): string
    {
        try {
            $step();
        } catch (DataRequestRefused $refused) {
            return $refused->reason;
        }

        self::fail('The step was not refused.');
    }

    private function submitted(User $applicant): DataRequest
    {
        $request = $this->service()->createDraft($applicant, $this->draft());
        $this->service()->submit($applicant, $request);

        return $request;
    }

    private function draft(): DataRequestDraft
    {
        $draft = new DataRequestDraft();
        $draft->title = 'Stroke pathways';
        $draft->summary = 'Summary';
        $draft->institution = 'University';
        $draft->purpose = 'Purpose';
        $draft->periodFrom = new \DateTimeImmutable('2024-01-01');
        $draft->periodTo = new \DateTimeImmutable('2024-12-31');
        $draft->population = 'All allocations';
        $draft->variables = 'Month';
        $draft->granularity = 'Month';
        $draft->recipients = 'Team';
        $draft->description = 'Description';
        $draft->dataNeeds = 'Data needs';
        $draft->responsiblePersons = 'Dr. A';
        $draft->conflictsOfInterest = 'none';
        $draft->ethics = 'Not needed';

        return $draft;
    }

    private function service(): DataRequestService
    {
        return self::getContainer()->get(DataRequestService::class);
    }

    private function review(): DataRequestReviewService
    {
        return self::getContainer()->get(DataRequestReviewService::class);
    }

    private function comments(): DataRequestCommentService
    {
        return self::getContainer()->get(DataRequestCommentService::class);
    }
}
