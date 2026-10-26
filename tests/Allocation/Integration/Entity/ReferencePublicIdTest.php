<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Entity;

use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Reference rows are identified by their public id in catalog packages (ADR 020).
 */
#[ResetDatabase]
final class ReferencePublicIdTest extends KernelTestCase
{
    use Factories;

    private const array TABLES = [
        'state',
        'dispatch_area',
        'department',
        'speciality',
        'assignment',
        'occasion',
        'infection',
        'secondary_transport',
        'indication_normalized',
        'indication_raw',
        'indication_group',
        'hospital',
    ];

    public function testPublicIdIsRequiredInEveryReferenceTable(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        /** @var array<string, string> $nullable */
        $nullable = $connection->fetchAllKeyValue(
            "SELECT table_name, is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'public_id' AND table_name IN (?)",
            [self::TABLES],
            [ArrayParameterType::STRING],
        );

        foreach (self::TABLES as $table) {
            self::assertSame('NO', $nullable[$table] ?? null, sprintf('%s.public_id must be NOT NULL', $table));
        }
    }

    public function testEveryReferenceTypeIsStoredWithAPublicId(): void
    {
        self::bootKernel();

        HospitalFactory::createOne();
        DepartmentFactory::createOne();
        SpecialityFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();
        InfectionFactory::createOne();
        SecondaryTransportFactory::createOne();
        IndicationRawFactory::createOne(['target' => IndicationNormalizedFactory::createOne()]);
        IndicationGroupFactory::createOne();
        DispatchAreaFactory::createOne();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        foreach (self::TABLES as $table) {
            $rows = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
            $withoutId = (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE public_id IS NULL', $table));

            self::assertGreaterThan(0, $rows, sprintf('%s has no rows', $table));
            self::assertSame(0, $withoutId, sprintf('%s has rows without public id', $table));
        }
    }

    public function testAPublicIdSetBeforePersistIsKept(): void
    {
        self::bootKernel();

        $given = Uuid::fromString('0192d6a1-6f5e-7c3a-9b1e-2f4a5c6d7e8f');
        $state = StateFactory::new()
            ->afterInstantiate(static function (State $state) use ($given): void {
                $state->setPublicId($given);
            })
            ->create();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $reloaded = $em->getRepository(State::class)->findOneBy(['publicId' => $given]);

        self::assertInstanceOf(State::class, $reloaded);
        self::assertSame($state->getId(), $reloaded->getId());
        self::assertTrue($given->equals($reloaded->getPublicId()));
    }
}
