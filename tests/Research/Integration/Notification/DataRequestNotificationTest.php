<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Notification;

use App\Research\Application\DataRequest\DataRequestCommentService;
use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Application\Message\EvaluateDataRequestDeadlinesMessage;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Enum\Visibility;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Infrastructure\Scheduler\EvaluateDataRequestDeadlinesMessageHandler;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Who hears what about a data extract request (ADR 045): the Board of rounds, vetoes, comments and withdrawals, the
 * applicant of decisions and comments, administrators of approvals, each once.
 */
#[ResetDatabase]
final class DataRequestNotificationTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private User $applicant;

    /** @var list<User> */
    private array $board;

    private User $admin;

    #[\Override]
    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-12-01 09:00:00'));
        $this->applicant = UserFactory::new()->asParticipant()->create();
        $this->board = [
            UserFactory::new()->asBoardMember()->create(),
            UserFactory::new()->asBoardMember()->create(),
        ];
        $this->admin = UserFactory::new()->asAdmin()->create();
    }

    public function testTheBoardHearsOfTheRoundAndTheApplicantAndAdministratorsOfTheApproval(): void
    {
        $request = $this->submitted();
        self::assertSame([], $this->types($this->board[0]), 'a submission is off by default');

        $this->review()->openRound($this->admin, $request);
        self::assertSame(['research.board_vote:opened'], $this->types($this->board[0]));
        self::assertSame(['research.board_vote:opened'], $this->types($this->board[1]));
        self::assertSame(['number' => (int) $request->getId(), 'title' => 'Stroke pathways', 'kind' => 'opened', 'round' => 1, 'version' => 1], $this->parameters($this->board[0], 'research.board_vote'));

        $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null);
        self::mockTime(new \DateTimeImmutable('2026-12-03 09:00:00'));
        $this->review()->castVote($this->board[1], $request, VoteChoice::Approve, null);

        self::assertSame(['research.decided'], $this->types($this->applicant));
        self::assertSame('approved', $this->parameters($this->applicant, 'research.decided')['outcome']);
        self::assertSame(['research.admin:approved'], $this->types($this->admin));
        self::assertSame('app_data_requests_show', $this->row($this->applicant, 'research.decided')['action_route']);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame('ROLE_BOARD_MEMBER', $connection->fetchOne("SELECT required_role FROM notification WHERE type = 'research.board_vote' LIMIT 1"), 'only Board members see Board types');
        self::assertSame('ROLE_ADMIN', $connection->fetchOne("SELECT required_role FROM notification WHERE type = 'research.admin' LIMIT 1"));
        self::assertNull($connection->fetchOne("SELECT required_role FROM notification WHERE type = 'research.decided' LIMIT 1"), 'decisions reach any applicant');
    }

    public function testCommentsReachTheApplicantAndThoseWhoTookPart(): void
    {
        $request = $this->submitted();
        $this->review()->openRound($this->admin, $request);
        $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null);
        $outsider = UserFactory::new()->asBoardMember()->create();

        $this->comments()->post($this->board[1], $request, 'Which hospitals?', Visibility::Private, null);
        self::assertSame(['research.commented:own'], $this->types($this->applicant), 'private comments reach the applicant');
        self::assertContains('research.commented:took_part', $this->types($this->board[0]), 'the member voted');
        self::assertNotContains('research.commented:took_part', $this->types($this->board[1]), 'not the author');
        self::assertSame([], $this->types($outsider), 'did not take part');

        $this->comments()->post($this->board[0], $request, 'Board only.', Visibility::Internal, null);
        self::assertCount(1, $this->rows($this->applicant, 'research.commented'), 'internal comments stay with the Board');
        self::assertCount(1, $this->rows($this->board[1], 'research.commented'), 'the author of the first comment took part');

        $this->comments()->post($this->applicant, $request, 'All participating ones.', Visibility::Private, null);
        self::assertCount(1, $this->rows($this->applicant, 'research.commented'), 'not of the own comment');
    }

    public function testAVetoStopsTheRequestAndAWithdrawalReachesTheBoard(): void
    {
        $request = $this->submitted();
        $this->review()->openRound($this->admin, $request);
        $this->review()->castVote($this->board[0], $request, VoteChoice::Veto, 'Too detailed.');

        self::assertSame('stalled', $this->parameters($this->applicant, 'research.decided')['outcome']);
        self::assertContains('research.board_status:vetoed', $this->types($this->board[1]));
        self::assertContains('research.admin:vetoed', $this->types($this->admin));
        self::assertNotContains('research.board_status:vetoed', $this->types($this->board[0]), 'not the member who vetoed');

        self::getContainer()->get(DataRequestService::class)->withdraw($this->applicant, $request);
        self::assertContains('research.board_status:withdrawn', $this->types($this->board[0]));
        self::assertNotContains('research.board_status:withdrawn', $this->types($this->applicant));
    }

    public function testARevisionReachesTheMembersWhoseApprovalWasCarriedOver(): void
    {
        $request = $this->submitted();
        $this->review()->openRound($this->admin, $request);
        $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null);
        $this->review()->castVote($this->board[1], $request, VoteChoice::Revision, 'Coarser age groups.');
        self::assertSame('revision_requested', $this->parameters($this->applicant, 'research.decided')['outcome']);

        $service = self::getContainer()->get(DataRequestService::class);
        $service->updateDraft($this->applicant, $request, $this->draft());
        $service->submit($this->applicant, $request);

        // The reason of the revision vote is a comment, which the other member hears of.
        self::assertSame(['research.board_vote:opened', 'research.commented:took_part', 'research.board_vote:revision'], $this->types($this->board[0]));
        self::assertSame(['research.board_vote:opened', 'research.board_vote:opened'], $this->types($this->board[1]));
    }

    public function testTheApplicantHearsOfReturnsVetoDecisionsAndExpiry(): void
    {
        $returned = $this->submitted();
        $this->review()->returnRequest($this->admin, $returned, 'Please name the age groups.');
        self::assertSame(['returned'], $this->outcomes());

        $closed = $this->submitted();
        $this->review()->openRound($this->admin, $closed);
        $this->review()->castVote($this->board[0], $closed, VoteChoice::Veto, 'No.');
        $this->review()->resolveStall($this->admin, $closed, 'close', null);
        self::assertSame(['returned', 'stalled', 'rejected'], $this->outcomes());

        $expiring = $this->submitted();
        $this->review()->openRound($this->admin, $expiring);
        $this->review()->castVote($this->board[0], $expiring, VoteChoice::Revision, null);
        $this->review()->castVote($this->board[1], $expiring, VoteChoice::Revision, null);
        self::mockTime(new \DateTimeImmutable('2026-12-20 09:00:00'));
        $this->runScheduler();
        self::assertSame(['returned', 'stalled', 'rejected', 'revision_requested', 'expired'], $this->outcomes());
    }

    /** @return list<string> */
    private function outcomes(): array
    {
        return array_map(static fn (array $row): string => (string) json_decode((string) $row['parameters'], true, flags: \JSON_THROW_ON_ERROR)['outcome'], $this->rows($this->applicant, 'research.decided'));
    }

    public function testTheSchedulerRemindsMembersWhoHaveNotVotedOnce(): void
    {
        $request = $this->submitted();
        $this->review()->openRound($this->admin, $request);
        $this->review()->castVote($this->board[0], $request, VoteChoice::Approve, null);

        self::mockTime(new \DateTimeImmutable('2026-12-13 08:00:00'));
        $this->runScheduler();
        self::assertNotContains('research.board_vote:reminder', $this->types($this->board[1]), 'more than 48 hours left');

        self::mockTime(new \DateTimeImmutable('2026-12-13 10:00:00'));
        $this->runScheduler();
        $this->runScheduler();
        self::assertSame(1, array_count_values($this->types($this->board[1]))['research.board_vote:reminder'] ?? 0);
        self::assertNotContains('research.board_vote:reminder', $this->types($this->board[0]), 'already voted');
    }

    private function runScheduler(): void
    {
        self::getContainer()->get(EvaluateDataRequestDeadlinesMessageHandler::class)(new EvaluateDataRequestDeadlinesMessage());
    }

    /**
     * Type and occasion of each notification, for example "research.board_vote:opened".
     *
     * @return list<string>
     */
    private function types(User $recipient): array
    {
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT type, parameters FROM notification WHERE recipient_id = ? ORDER BY id', [$recipient->getId()]);

        return array_map(static function (array $row): string {
            /** @var array<string, mixed> $parameters */
            $parameters = json_decode((string) $row['parameters'], true, flags: \JSON_THROW_ON_ERROR);
            $occasion = $parameters['kind'] ?? (isset($parameters['own']) ? ($parameters['own'] ? 'own' : 'took_part') : null);

            return (string) $row['type'].(null === $occasion ? '' : ':'.$occasion);
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function rows(User $recipient, string $type): array
    {
        return self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT parameters, action_route FROM notification WHERE recipient_id = ? AND type = ? ORDER BY id', [$recipient->getId(), $type]);
    }

    /** @return array<string, mixed> */
    private function row(User $recipient, string $type): array
    {
        $rows = $this->rows($recipient, $type);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return array<string, mixed> */
    private function parameters(User $recipient, string $type): array
    {
        /** @var array<string, mixed> $parameters */
        $parameters = json_decode((string) $this->row($recipient, $type)['parameters'], true, flags: \JSON_THROW_ON_ERROR);

        return $parameters;
    }

    private function submitted(): DataRequest
    {
        $service = self::getContainer()->get(DataRequestService::class);
        $request = $service->createDraft($this->applicant, $this->draft());
        $service->submit($this->applicant, $request);

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

    private function review(): DataRequestReviewService
    {
        return self::getContainer()->get(DataRequestReviewService::class);
    }

    private function comments(): DataRequestCommentService
    {
        return self::getContainer()->get(DataRequestCommentService::class);
    }
}
