<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Indication;

use App\Allocation\Application\Indication\IndicationRawCorruptionMergeService;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
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
    private function seedStubPair(): array
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
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
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
     * @return array{allocationDefaults: array<string, mixed>}
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
