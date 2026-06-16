<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class ReferenceFixtureLoadTest extends KernelTestCase
{
    #[Test]
    public function referenceGroupLoadsExpectedEntityCounts(): void
    {
        self::bootKernel();

        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');

        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');

        $fixtures = $loader->getFixtures(['reference']);
        $purger = $purgerFactory->createForEntityManager('default', $entityManager, []);
        $executor = new ORMExecutor($entityManager, $purger);
        $executor->execute($fixtures, true);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(25, (int) $connection->fetchOne('SELECT COUNT(*) FROM dispatch_area'));
        self::assertSame(77, (int) $connection->fetchOne('SELECT COUNT(*) FROM hospital'));
        self::assertGreaterThanOrEqual(108, (int) $connection->fetchOne('SELECT COUNT(*) FROM department'));
        self::assertGreaterThanOrEqual(20, (int) $connection->fetchOne('SELECT COUNT(*) FROM speciality'));
        self::assertSame(7, (int) $connection->fetchOne('SELECT COUNT(*) FROM assignment'));
        self::assertSame(29, (int) $connection->fetchOne('SELECT COUNT(*) FROM occasion'));
        self::assertSame(19, (int) $connection->fetchOne('SELECT COUNT(*) FROM infection'));
        self::assertSame(8, (int) $connection->fetchOne('SELECT COUNT(*) FROM secondary_transport'));
        self::assertSame(210, (int) $connection->fetchOne('SELECT COUNT(*) FROM indication_normalized'));
        self::assertGreaterThanOrEqual(210, (int) $connection->fetchOne('SELECT COUNT(*) FROM indication_raw'));

        $unlinked = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM indication_normalized n
             LEFT JOIN indication_raw r ON r.normalized_id = n.id
             WHERE r.id IS NULL',
        );
        self::assertSame(0, $unlinked, 'Every normalized indication must have a linked raw row.');
    }
}
