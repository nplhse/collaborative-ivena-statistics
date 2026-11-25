<?php

declare(strict_types=1);

namespace App\Tests\Research\Integration\Application;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Enum\DataRequestStatus;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The applicant's steps on a data extract request and the steps the service refuses (ADR 045).
 */
#[ResetDatabase]
final class DataRequestServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testTheApplicantChangesADraftAndSubmitsIt(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-25 10:00:00'));
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->service()->createDraft($applicant, $this->draft('First title'));

        self::mockTime(new \DateTimeImmutable('2026-11-25 11:00:00'));
        $this->service()->updateDraft($applicant, $request, $this->draft('Second title'));
        self::assertSame('Second title', $request->getCurrentVersion()->getTitle());
        self::assertEquals(new \DateTimeImmutable('2026-11-25 11:00:00'), $request->getCurrentVersion()->getUpdatedAt());

        self::mockTime(new \DateTimeImmutable('2026-11-25 12:00:00'));
        $this->service()->submit($applicant, $request);
        self::assertSame(DataRequestStatus::Submitted, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-25 12:00:00'), $request->getCurrentVersion()->getSubmittedAt());
    }

    public function testOthersCannotChangeSubmitOrWithdrawARequest(): void
    {
        $request = $this->service()->createDraft(UserFactory::new()->asParticipant()->create(), $this->draft('Title'));
        $other = UserFactory::new()->asParticipant()->create();

        foreach ([
            fn () => $this->service()->updateDraft($other, $request, $this->draft('Changed')),
            fn () => $this->service()->submit($other, $request),
            fn () => $this->service()->withdraw($other, $request),
        ] as $step) {
            self::assertSame(DataRequestRefused::NOT_OWN, $this->refusal($step));
        }

        self::assertSame(DataRequestStatus::Draft, $request->getStatus());
        self::assertSame('Title', $request->getCurrentVersion()->getTitle());
    }

    public function testASubmittedVersionCannotChange(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->service()->createDraft($applicant, $this->draft('Title'));
        $this->service()->submit($applicant, $request);

        self::assertSame(DataRequestRefused::NOT_EDITABLE, $this->refusal(fn () => $this->service()->updateDraft($applicant, $request, $this->draft('Changed'))));
    }

    public function testAChangeToAReturnedRequestBecomesTheNextVersion(): void
    {
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->service()->createDraft($applicant, $this->draft('Title'));
        $this->service()->submit($applicant, $request);
        // Returned by the formal check.
        $request->setMarking('returned');

        self::assertSame(DataRequestRefused::NEW_VERSION_NEEDED, $this->refusal(fn () => $this->service()->submit($applicant, $request)));
        $this->service()->updateDraft($applicant, $request, $this->draft('Changed'));
        $this->service()->updateDraft($applicant, $request, $this->draft('Changed again'));

        self::assertCount(2, $request->getVersions(), 'the first change starts version 2, later ones change it');
        self::assertSame('Title', $request->getVersions()[0]->getTitle());
        self::assertSame('Changed again', $request->getCurrentVersion()->getTitle());
    }

    public function testAWithdrawnRequestTakesNoFurtherStep(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-25 10:00:00'));
        $applicant = UserFactory::new()->asParticipant()->create();
        $request = $this->service()->createDraft($applicant, $this->draft('Title'));
        $this->service()->withdraw($applicant, $request);
        self::assertSame(DataRequestStatus::Withdrawn, $request->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-11-25 10:00:00'), $request->getClosedAt());

        self::assertSame(DataRequestRefused::TRANSITION_BLOCKED, $this->refusal(fn () => $this->service()->withdraw($applicant, $request)));
        self::assertSame(DataRequestRefused::TRANSITION_BLOCKED, $this->refusal(fn () => $this->service()->submit($applicant, $request)));
    }

    /**
     * @param callable(): void $step
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

    private function draft(string $title): DataRequestDraft
    {
        $draft = new DataRequestDraft();
        $draft->title = $title;
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
}
