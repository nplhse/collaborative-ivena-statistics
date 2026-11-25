<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Application;

use App\Research\Application\DataRequest\DataRequestCommentService;
use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestComment;
use App\Research\Domain\Enum\VoteChoice;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Who comments on a data extract request, and which comments are private (ADR 045).
 */
#[ResetDatabase]
final class DataRequestCommentServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testWhoMayCommentAndWhoSeesPrivateComments(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $participant = UserFactory::new()->asParticipant()->create();
        $draft = $this->service()->createDraft($applicant, $this->draft());
        self::assertFalse($this->comments()->canComment($applicant, $draft), 'no discussion on a draft');

        $request = $this->submitted($applicant);

        self::assertTrue($this->comments()->canComment($applicant, $request));
        self::assertTrue($this->comments()->canComment($member, $request));
        self::assertTrue($this->comments()->canComment($admin, $request));
        self::assertFalse($this->comments()->canComment($participant, $request), 'other participants only read');

        self::assertTrue($this->comments()->canSeePrivate($member, $request));
        self::assertFalse($this->comments()->canSeePrivate($applicant, $request));
        self::assertFalse($this->comments()->canSeePrivate($admin, $request), 'private comments are for the Board');
    }

    public function testAReplyToAPrivateCommentStaysPrivate(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-12-01 09:00:00'));
        $applicant = UserFactory::new()->asParticipant()->create();
        [$member, $other] = [UserFactory::new()->asBoardMember()->create(), UserFactory::new()->asBoardMember()->create()];
        $request = $this->submitted($applicant);

        $this->comments()->post($member, $request, 'Board only: the period seems long.', true, null);
        $private = $this->only($request);
        self::assertTrue($private->isPrivate());
        self::assertSame(1, $private->getVersion()->getNumber());

        // The form sent "public"; the reply to a private comment stays private.
        $this->comments()->post($other, $request, 'Agreed.', false, $private->getId());
        self::assertTrue($private->getChildren()[0]->isPrivate());

        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($applicant, $request, 'Can I see this?', false, $private->getId())));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($applicant, $request, 'Secret?', true, null)));
    }

    public function testAPublicCommentMayGetAPrivateReply(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $request = $this->submitted($applicant);

        $this->comments()->post($applicant, $request, 'The period covers 2024.', false, null);
        $public = $this->only($request);
        $this->comments()->post($member, $request, 'Board: fine for us.', true, $public->getId());

        self::assertFalse($public->isPrivate());
        self::assertTrue($public->getChildren()[0]->isPrivate());
    }

    public function testAnExcludedMemberNoLongerSeesPrivateComments(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $member = UserFactory::new()->asBoardMember()->create();
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->submitted($applicant);

        $this->review()->declareExclusion($admin, $request, $member, 'Co-author.');

        self::assertFalse($this->comments()->canSeePrivate($member, $request));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($member, $request, 'Private?', true, null)));
    }

    public function testEmptyCommentsAndUnknownParentsAreRefused(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->submitted($applicant);

        self::assertSame(DataRequestRefused::COMMENT_INVALID, $this->refusal(fn () => $this->comments()->post($applicant, $request, '   ', false, null)));
        self::assertSame(DataRequestRefused::COMMENT_INVALID, $this->refusal(fn () => $this->comments()->post($applicant, $request, str_repeat('x', 5001), false, null)));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post($applicant, $request, 'Reply', false, 999999)));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->comments()->post(UserFactory::new()->asParticipant()->create(), $request, 'Hello', false, null)));
    }

    public function testAVoteReasonBecomesACommentOnlyWhenChosen(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        [$a, $b, $c] = [UserFactory::new()->asBoardMember()->create(), UserFactory::new()->asBoardMember()->create(), UserFactory::new()->asBoardMember()->create()];
        $request = $this->submitted($applicant);
        $this->review()->openRound($a, $request);

        $this->review()->castVote($a, $request, VoteChoice::Approve, 'Only for the vote.');
        $this->review()->castVote($b, $request, VoteChoice::Revision, 'Coarser age groups.', DataRequestReviewService::REASON_PUBLIC);
        $this->review()->castVote($c, $request, VoteChoice::Approve, 'Fine with me.', DataRequestReviewService::REASON_PRIVATE);

        $comments = $request->getTopLevelComments();
        self::assertCount(2, $comments);
        self::assertSame(['Coarser age groups.', 'Fine with me.'], array_map(static fn (DataRequestComment $comment): string => $comment->getBody(), $comments));
        self::assertSame([false, true], array_map(static fn (DataRequestComment $comment): bool => $comment->isPrivate(), $comments));
        self::assertSame(VoteChoice::Revision, $comments[0]->getVote()?->getChoice());
        self::assertSame($b, $comments[0]->getAuthor());
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
