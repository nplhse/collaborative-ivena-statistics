<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Application;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Domain\Entity\DataRequest;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;

/**
 * Validation of the request form and the round trip between a version and the form (ADR 045).
 */
final class DataRequestDraftTest extends TestCase
{
    public function testACompleteDraftIsValid(): void
    {
        self::assertSame([], $this->violations($this->complete()));
    }

    public function testAnEmptyDraftNamesEveryRequiredField(): void
    {
        $paths = $this->violations(new DataRequestDraft());

        self::assertSame(
            ['title', 'summary', 'institution', 'purpose', 'periodFrom', 'periodTo', 'population', 'variables', 'granularity', 'recipients', 'description', 'dataNeeds', 'responsiblePersons', 'conflictsOfInterest', 'ethics'],
            $paths,
        );
    }

    public function testThePeriodMustNotEndBeforeItStarts(): void
    {
        $draft = $this->complete();
        $draft->periodFrom = new \DateTimeImmutable('2025-01-01');
        $draft->periodTo = new \DateTimeImmutable('2024-12-31');

        self::assertSame(['periodTo'], $this->violations($draft));
    }

    public function testAPlannedEnrichmentNeedsItsDetails(): void
    {
        $draft = $this->complete();
        $draft->enrichmentPlanned = true;
        self::assertSame(['enrichmentDetails'], $this->violations($draft));

        $draft->enrichmentDetails = 'Local stroke registry, linked by month';
        self::assertSame([], $this->violations($draft));
    }

    public function testADraftRoundTripsThroughAVersion(): void
    {
        $draft = $this->complete();
        $draft->enrichmentPlanned = true;
        $draft->enrichmentDetails = 'Registry';
        $draft->singleCases = true;
        $draft->plannedEnd = new \DateTimeImmutable('2027-06-30');
        $draft->plannedDeletion = new \DateTimeImmutable('2028-06-30');
        $draft->plannedPublication = 'Journal article';

        $request = new DataRequest(new User(), $draft->toContent(), new \DateTimeImmutable('2026-11-25 10:00'));
        $copy = DataRequestDraft::fromVersion($request->getCurrentVersion());

        self::assertEquals($draft, $copy);
        self::assertSame($request, $request->getCurrentVersion()->getRequest());
        self::assertEquals(new \DateTimeImmutable('2026-11-25 10:00'), $request->getCurrentVersion()->getCreatedAt());
    }

    public function testAnUnvalidatedDraftCannotBecomeContent(): void
    {
        $this->expectException(\LogicException::class);

        new DataRequestDraft()->toContent();
    }

    /**
     * @return list<string>
     */
    private function violations(DataRequestDraft $draft): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        return array_values(array_map(
            static fn (ConstraintViolationInterface $violation): string => $violation->getPropertyPath(),
            iterator_to_array($validator->validate($draft)),
        ));
    }

    private function complete(): DataRequestDraft
    {
        $draft = new DataRequestDraft();
        $draft->title = 'Title';
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
}
