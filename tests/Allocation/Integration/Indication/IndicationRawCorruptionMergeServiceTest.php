<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Indication;

use App\Allocation\Application\Indication\IndicationRawCorruptionMergeService;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Domain\IndicationKey;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\MciCaseFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationRawCorruptionMergeServiceTest extends KernelTestCase
{
    use Factories;

    public function testDryRunDoesNotPersistQuoteVariantMerge(): void
    {
        $fixture = $this->seedQuoteVariants();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(true);

        self::assertGreaterThanOrEqual(1, \count($result->actions));
        self::assertSame('quote_variant', $result->actions[0]->type);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertNotFalse($connection->fetchOne('SELECT id FROM indication_raw WHERE id = :id', ['id' => $fixture['loserId']]));
        self::assertSame(
            $fixture['loserId'],
            (int) $connection->fetchOne('SELECT indication_raw_id FROM allocation WHERE id = :id', ['id' => $fixture['allocationId']]),
        );
    }

    public function testMergesQuoteVariantsOntoMatchedSurvivor(): void
    {
        $fixture = $this->seedQuoteVariants();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $merged = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'quote_variant' === $action->type && $action->loserId === $fixture['loserId'],
        ));
        self::assertCount(1, $merged);
        self::assertSame($fixture['survivorId'], $merged[0]->survivorId);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertFalse($connection->fetchOne('SELECT id FROM indication_raw WHERE id = :id', ['id' => $fixture['loserId']]));
        self::assertSame(
            $fixture['survivorId'],
            (int) $connection->fetchOne('SELECT indication_raw_id FROM allocation WHERE id = :id', ['id' => $fixture['allocationId']]),
        );
        self::assertSame(
            $fixture['normalizedId'],
            (int) $connection->fetchOne('SELECT indication_normalized_id FROM allocation WHERE id = :id', ['id' => $fixture['allocationId']]),
        );
    }

    public function testMergesFourCharacterStubOntoIntactRaw(): void
    {
        $fixture = $this->seedStubPair();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $stubActions = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'stub' === $action->type && $action->loserId === $fixture['stubId'],
        ));
        self::assertCount(1, $stubActions);
        self::assertSame($fixture['intactId'], $stubActions[0]->survivorId);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertFalse($connection->fetchOne('SELECT id FROM indication_raw WHERE id = :id', ['id' => $fixture['stubId']]));
        self::assertSame(
            $fixture['intactId'],
            (int) $connection->fetchOne('SELECT indication_raw_id FROM allocation WHERE id = :id', ['id' => $fixture['allocationId']]),
        );
    }

    public function testMergesNeedsReviewStubOntoIntactRaw(): void
    {
        $fixture = $this->seedStubPair(IndicationRawReviewStatus::NeedsReview);
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $stubActions = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'stub' === $action->type && $action->loserId === $fixture['stubId'],
        ));
        self::assertCount(1, $stubActions);
        self::assertSame($fixture['intactId'], $stubActions[0]->survivorId);
    }

    public function testRestoresStubFromCatalogWhenNoIntactSiblingExists(): void
    {
        $fixture = $this->seedCatalogStub();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $restores = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'stub_restore' === $action->type && $action->loserId === $fixture['stubId'],
        ));
        self::assertCount(1, $restores);
        self::assertNull($restores[0]->survivorId);
        self::assertSame('Gefäßchirurgischer Notfall, sonstiger', $restores[0]->afterName);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative('SELECT name, hash, review_status, target_id FROM indication_raw WHERE id = :id', ['id' => $fixture['stubId']]);
        self::assertIsArray($row);
        self::assertSame('Gefäßchirurgischer Notfall, sonstiger', $row['name']);
        self::assertSame(
            IndicationKey::hashFrom('299', 'Gefäßchirurgischer Notfall, sonstiger'),
            $row['hash'],
        );
        self::assertSame(IndicationRawReviewStatus::Matched->value, $row['review_status']);
        self::assertSame($fixture['catalogId'], (int) $row['target_id']);
        self::assertContains($fixture['importId'], $result->affectedImportIds);
    }

    public function testStubRestoreMergesOntoExistingRawWithRestoredHash(): void
    {
        $fixture = $this->seedStubRestoreHashCollision();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $merged = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => $action->loserId === $fixture['stubId'],
        ));
        self::assertNotEmpty($merged);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertFalse($connection->fetchOne('SELECT id FROM indication_raw WHERE id = :id', ['id' => $fixture['stubId']]));
        self::assertSame(
            $fixture['existingId'],
            (int) $connection->fetchOne('SELECT indication_raw_id FROM allocation WHERE id = :id', ['id' => $fixture['allocationId']]),
        );
    }

    public function testMovesSecondaryIndicationAndMciCaseOntoSurvivor(): void
    {
        $fixture = $this->seedQuoteVariantsWithSecondaryAndMci();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        self::assertContains($fixture['importId'], $result->affectedImportIds);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(
            $fixture['survivorId'],
            (int) $connection->fetchOne('SELECT secondary_indication_raw_id FROM allocation WHERE id = :id', ['id' => $fixture['secondaryAllocationId']]),
        );
        self::assertSame(
            $fixture['normalizedId'],
            (int) $connection->fetchOne('SELECT secondary_indication_normalized_id FROM allocation WHERE id = :id', ['id' => $fixture['secondaryAllocationId']]),
        );
        self::assertSame(
            $fixture['survivorId'],
            (int) $connection->fetchOne('SELECT indication_raw_id FROM mci_case WHERE id = :id', ['id' => $fixture['mciId']]),
        );
    }

    public function testChoosesUnmatchedSurvivorWithHigherOccurrenceThenLowerId(): void
    {
        $fixture = $this->seedUnmatchedQuoteVariantsByOccurrence();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $merged = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'quote_variant' === $action->type,
        ));
        self::assertCount(1, $merged);
        self::assertSame($fixture['highOccurrenceId'], $merged[0]->survivorId);
        self::assertSame($fixture['lowOccurrenceId'], $merged[0]->loserId);
    }

    public function testTargetIdCountsAsMatchedWhenChoosingSurvivor(): void
    {
        $fixture = $this->seedTargetIdBeatsUnreviewed();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $merged = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'quote_variant' === $action->type,
        ));
        self::assertCount(1, $merged);
        self::assertSame($fixture['targetIdRawId'], $merged[0]->survivorId);
    }

    public function testSkipsOpenStubWithoutCatalogOrIntactSibling(): void
    {
        IndicationRawFactory::createOne([
            'code' => 298,
            'name' => 'Kein Katalogtreffer',
            'hash' => 'orphan-stub',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);
        IndicationNormalizedFactory::createOne([
            'code' => 298,
            'name' => 'Komplett anderer Katalogname',
        ]);

        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);
        $result = $service->run(true);

        self::assertSame([], array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'Kein Katalogtreffer' === $action->beforeName,
        )));
    }

    public function testSkipsConsumedQuoteVariantWhenLookingForIntactSibling(): void
    {
        $fixture = $this->seedConsumedIntactSibling();
        $service = self::getContainer()->get(IndicationRawCorruptionMergeService::class);

        $result = $service->run(false);

        $stubActions = array_values(array_filter(
            $result->actions,
            static fn ($action): bool => 'stub' === $action->type && $action->loserId === $fixture['stubId'],
        ));
        self::assertCount(1, $stubActions);
        self::assertSame($fixture['intactId'], $stubActions[0]->survivorId);
    }

    /**
     * @return array{survivorId: int, loserId: int, allocationId: int, normalizedId: int}
     */
    private function seedQuoteVariants(): array
    {
        $graph = $this->seedGraph();
        $normalized = IndicationNormalizedFactory::createOne([
            'code' => 332,
            'name' => "STEMI/\u{201C}OMI\u{201D}",
        ]);
        $survivor = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\"OMI\\"',
            'hash' => 'legacy-backslash-omi',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
            'target' => $normalized,
        ]);
        $loser = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\OMI\\""',
            'hash' => 'legacy-rfc-leftover-omi',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        $allocation = AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $loser,
            'indicationNormalized' => null,
        ]);

        return [
            'survivorId' => (int) $survivor->getId(),
            'loserId' => (int) $loser->getId(),
            'allocationId' => (int) $allocation->getId(),
            'normalizedId' => (int) $normalized->getId(),
        ];
    }

    /**
     * @return array{stubId: int, intactId: int, allocationId: int}
     */
    private function seedStubPair(IndicationRawReviewStatus $stubStatus = IndicationRawReviewStatus::Unreviewed): array
    {
        $graph = $this->seedGraph();
        $intact = IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'Gefäßchirurgischer Notfall, sonstiger',
            'hash' => 'intact-gefaess',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
        ]);
        $stub = IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'ßchirurgischer Notfall, sonstiger',
            'hash' => 'stub-gefaess',
            'reviewStatus' => $stubStatus,
        ]);

        $allocation = AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $stub,
            'indicationNormalized' => null,
        ]);

        return [
            'stubId' => (int) $stub->getId(),
            'intactId' => (int) $intact->getId(),
            'allocationId' => (int) $allocation->getId(),
        ];
    }

    /**
     * @return array{stubId: int, catalogId: int, importId: int}
     */
    private function seedCatalogStub(): array
    {
        $graph = $this->seedGraph();
        $catalog = IndicationNormalizedFactory::createOne([
            'code' => 299,
            'name' => 'Gefäßchirurgischer Notfall, sonstiger',
        ]);
        $stub = IndicationRawFactory::createOne([
            'code' => 299,
            'name' => 'ßchirurgischer Notfall, sonstiger',
            'hash' => 'catalog-stub-gefaess',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $stub,
            'indicationNormalized' => null,
        ]);

        return [
            'stubId' => (int) $stub->getId(),
            'catalogId' => (int) $catalog->getId(),
            'importId' => (int) $graph['import']->getId(),
        ];
    }

    /**
     * @return array{stubId: int, existingId: int, allocationId: int}
     */
    private function seedStubRestoreHashCollision(): array
    {
        $graph = $this->seedGraph();
        IndicationNormalizedFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / "OMI"',
        ]);
        $existing = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\OMI\\""',
            'hash' => 'collision-leftover',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);
        $stub = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'I / "OMI"',
            'hash' => 'collision-stub',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        $allocation = AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $stub,
            'indicationNormalized' => null,
        ]);

        return [
            'stubId' => (int) $stub->getId(),
            'existingId' => (int) $existing->getId(),
            'allocationId' => (int) $allocation->getId(),
        ];
    }

    /**
     * @return array{survivorId: int, secondaryAllocationId: int, mciId: int, normalizedId: int, importId: int}
     */
    private function seedQuoteVariantsWithSecondaryAndMci(): array
    {
        $graph = $this->seedGraph();
        $normalized = IndicationNormalizedFactory::createOne([
            'code' => 332,
            'name' => "STEMI/\u{201C}OMI\u{201D}",
        ]);
        $survivor = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\"OMI\\"',
            'hash' => 'legacy-backslash-omi-sec',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
            'target' => $normalized,
        ]);
        $loser = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\OMI\\""',
            'hash' => 'legacy-rfc-leftover-omi-sec',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);
        $primaryOther = IndicationRawFactory::createOne([
            'code' => 100,
            'name' => 'Andere Indikation',
            'hash' => 'other-primary-indication',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        $secondaryAllocation = AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $primaryOther,
            'indicationNormalized' => null,
            'secondaryIndicationRaw' => $loser,
            'secondaryIndicationNormalized' => null,
        ]);
        $mci = MciCaseFactory::createOne([
            'hospital' => $graph['hospital'],
            'dispatchArea' => $graph['dispatchArea'],
            'state' => $graph['state'],
            'import' => $graph['import'],
            'indicationRaw' => $loser,
            'indicationNormalized' => null,
            'createdAt' => new \DateTimeImmutable('2025-06-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 08:30:00'),
            'mciId' => 'mci-quote-'.bin2hex(random_bytes(4)),
            'mciTitle' => 'Quote leftover MCI',
        ]);

        return [
            'survivorId' => (int) $survivor->getId(),
            'secondaryAllocationId' => (int) $secondaryAllocation->getId(),
            'mciId' => (int) $mci->getId(),
            'normalizedId' => (int) $normalized->getId(),
            'importId' => (int) $graph['import']->getId(),
        ];
    }

    /**
     * @return array{highOccurrenceId: int, lowOccurrenceId: int}
     */
    private function seedUnmatchedQuoteVariantsByOccurrence(): array
    {
        $graph = $this->seedGraph();
        $low = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\"OMI\\"',
            'hash' => 'occ-low',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);
        $high = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\OMI\\""',
            'hash' => 'occ-high',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $low,
            'indicationNormalized' => null,
        ]);
        AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $high,
            'indicationNormalized' => null,
            'createdAt' => new \DateTimeImmutable('2025-06-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 09:30:00'),
        ]);
        AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $high,
            'indicationNormalized' => null,
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 10:30:00'),
        ]);

        return [
            'highOccurrenceId' => (int) $high->getId(),
            'lowOccurrenceId' => (int) $low->getId(),
        ];
    }

    /**
     * @return array{targetIdRawId: int}
     */
    private function seedTargetIdBeatsUnreviewed(): array
    {
        $graph = $this->seedGraph();
        $normalized = IndicationNormalizedFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / "OMI"',
        ]);
        $withTarget = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\"OMI\\"',
            'hash' => 'target-raw',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
            'target' => $normalized,
        ]);
        $plain = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\OMI\\""',
            'hash' => 'plain-raw',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $plain,
            'indicationNormalized' => null,
        ]);
        AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $plain,
            'indicationNormalized' => null,
            'createdAt' => new \DateTimeImmutable('2025-06-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-06-01 09:30:00'),
        ]);

        return [
            'targetIdRawId' => (int) $withTarget->getId(),
        ];
    }

    /**
     * @return array{stubId: int, intactId: int}
     */
    private function seedConsumedIntactSibling(): array
    {
        $graph = $this->seedGraph();
        $intact = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / "OMI"',
            'hash' => 'consumed-intact',
            'reviewStatus' => IndicationRawReviewStatus::Matched,
        ]);
        IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'STEMI / \\OMI\\""',
            'hash' => 'consumed-leftover',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);
        $stub = IndicationRawFactory::createOne([
            'code' => 332,
            'name' => 'I / "OMI"',
            'hash' => 'consumed-stub',
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        AllocationFactory::createOne([
            ...$graph['allocationDefaults'],
            'indicationRaw' => $stub,
            'indicationNormalized' => null,
        ]);

        return [
            'stubId' => (int) $stub->getId(),
            'intactId' => (int) $intact->getId(),
        ];
    }

    /**
     * @return array{
     *     allocationDefaults: array<string, mixed>,
     *     hospital: object,
     *     import: object,
     *     state: object,
     *     dispatchArea: object
     * }
     */
    private function seedGraph(): array
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdBy' => $user,
        ]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();

        return [
            'hospital' => $hospital,
            'import' => $import,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'allocationDefaults' => [
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'createdAt' => new \DateTimeImmutable('2025-06-01 08:00:00'),
                'arrivalAt' => new \DateTimeImmutable('2025-06-01 08:30:00'),
                'gender' => AllocationGender::MALE,
                'urgency' => AllocationUrgency::EMERGENCY,
                'transportType' => AllocationTransportType::GROUND,
                'infection' => null,
                'secondaryTransport' => null,
            ],
        ];
    }
}
