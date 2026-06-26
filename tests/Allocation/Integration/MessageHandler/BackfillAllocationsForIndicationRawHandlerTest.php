<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\MessageHandler;

use App\Allocation\Application\Message\BackfillAllocationsForIndicationRawMessage;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillAllocationsForIndicationRawHandlerTest extends KernelTestCase
{
    use Factories;

    public function testHandlerBackfillsAllocationForMatchedRaw(): void
    {
        self::bootKernel();

        $normalized = IndicationNormalizedFactory::createOne(['code' => 777, 'name' => 'Handler Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 777,
            'name' => 'Handler Raw',
            'target' => $normalized,
            'reviewStatus' => IndicationRawReviewStatus::Matched,
        ]);

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

        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => null,
            'gender' => AllocationGender::MALE,
            'transportType' => AllocationTransportType::GROUND,
            'urgency' => AllocationUrgency::EMERGENCY,
        ]);

        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new BackfillAllocationsForIndicationRawMessage((int) $raw->getId()));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $normalizedId = $connection->fetchOne(
            'SELECT indication_normalized_id FROM allocation WHERE id = :id',
            ['id' => $allocation->getId()],
        );

        self::assertSame($normalized->getId(), (int) $normalizedId);
    }
}
