<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Application;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestQueryService;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestReviewService;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestEvent;
use App\Research\Domain\Entity\DataRequestQuery;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\VoteChoice;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Administrators manage the export queries of an approved request (ADR 045): several queries, each with its own
 * versions, check and dry run.
 */
#[ResetDatabase]
final class DataRequestQueryServiceTest extends KernelTestCase
{
    use Factories;

    private const string ALLOWED = 'SELECT count(*) AS allocations, array_agg(DISTINCT a.hospital_id) AS _hospital_ids FROM allocation a';
    private const string BLOCKED = 'SELECT h.name, array_agg(DISTINCT h.id) AS _hospital_ids FROM hospital h GROUP BY h.name';

    public function testAnAdministratorAddsSeveralQueriesAndRevisesOne(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved();

        $this->queries()->add($admin, $request, ' total ', 'All allocations.', QueryKind::Aggregated, self::ALLOWED);
        $this->queries()->add($admin, $request, 'names', 'Hospital names.', QueryKind::Aggregated, self::BLOCKED);

        $total = $this->query($request, 'total');
        $names = $this->query($request, 'names');
        self::assertCount(2, $request->getQueries());
        self::assertTrue($total->getCurrentVersion()->passesCheck());
        self::assertFalse($names->getCurrentVersion()->passesCheck());

        $this->queries()->revise($admin, $total, 'Only the description changes.', self::ALLOWED);
        self::assertCount(1, $total->getVersions(), 'the same SQL is no new version');
        self::assertSame('Only the description changes.', $total->getDescription());

        $this->queries()->revise($admin, $total, 'All allocations.', self::ALLOWED.' HAVING count(*) > 0');
        self::assertCount(2, $total->getVersions());
        self::assertTrue($total->getCurrentVersion()->passesCheck());

        $saved = array_filter($request->getEvents(), static fn (DataRequestEvent $event): bool => TimelineEventType::QuerySaved === $event->getType());
        self::assertCount(3, $saved, 'two added queries and one new version');
    }

    public function testADryRunNeedsAPassingCheck(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved();
        $this->queries()->add($admin, $request, 'total', 'All allocations.', QueryKind::Aggregated, self::ALLOWED);
        $this->queries()->add($admin, $request, 'names', 'Hospital names.', QueryKind::Aggregated, self::BLOCKED);

        $this->queries()->dryRun($admin, $this->query($request, 'total'));
        self::assertSame(1, $this->query($request, 'total')->getCurrentVersion()->getDryRun()['rows'] ?? null, 'one total row, no allocations in the fixture');

        self::assertSame(DataRequestRefused::QUERY_CHECK_FAILED, $this->refusal(fn () => $this->queries()->dryRun($admin, $this->query($request, 'names'))));
        self::assertNull($this->query($request, 'names')->getCurrentVersion()->getDryRun());
    }

    public function testOnlyAdministratorsManageQueriesOfApprovedRequestsAndInvalidOnesAreRefused(): void
    {
        $admin = UserFactory::new()->asAdmin()->create();
        $request = $this->approved();
        $this->queries()->add($admin, $request, 'total', 'All allocations.', QueryKind::Aggregated, self::ALLOWED);
        $total = $this->query($request, 'total');

        $member = UserFactory::new()->asBoardMember()->create();
        self::assertFalse($this->queries()->canManage($member, $request));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->queries()->add($member, $request, 'x', 'X.', QueryKind::Aggregated, self::ALLOWED)));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->queries()->revise($member, $total, 'X.', self::ALLOWED)));
        self::assertSame(DataRequestRefused::NOT_ALLOWED, $this->refusal(fn () => $this->queries()->dryRun($member, $total)));

        $submitted = $this->submitted(UserFactory::new()->asParticipant()->create());
        self::assertFalse($this->queries()->canManage($admin, $submitted), 'no queries before the approval');

        self::assertSame(DataRequestRefused::QUERY_INVALID, $this->refusal(fn () => $this->queries()->add($admin, $request, 'total', 'Again.', QueryKind::Aggregated, self::ALLOWED)));
        self::assertSame(DataRequestRefused::QUERY_INVALID, $this->refusal(fn () => $this->queries()->revise($admin, $total, ' ', self::ALLOWED)));
        self::assertSame(DataRequestRefused::QUERY_INVALID, $this->refusal(fn () => $this->queries()->revise($admin, $total, 'X.', ' ')));
    }

    private function query(DataRequest $request, string $name): DataRequestQuery
    {
        $query = $request->findQuery($name);
        self::assertInstanceOf(DataRequestQuery::class, $query);

        return $query;
    }

    private function refusal(callable $step): string
    {
        try {
            $step();
        } catch (DataRequestRefused $refused) {
            return $refused->reason;
        }

        self::fail('The step was not refused.');
    }

    private function approved(): DataRequest
    {
        $request = $this->submitted(UserFactory::new()->asParticipant()->create());
        $member = UserFactory::new()->asBoardMember()->create();
        $review = self::getContainer()->get(DataRequestReviewService::class);
        $review->openRound($member, $request);
        $review->castVote($member, $request, VoteChoice::Approve, null);
        self::assertSame('approved', $request->getStatus()->value);

        return $request;
    }

    private function submitted(User $applicant): DataRequest
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

        $service = self::getContainer()->get(DataRequestService::class);
        $request = $service->createDraft($applicant, $draft);
        $service->submit($applicant, $request);

        return $request;
    }

    private function queries(): DataRequestQueryService
    {
        return self::getContainer()->get(DataRequestQueryService::class);
    }
}
