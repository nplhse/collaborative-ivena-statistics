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
final class IndicationGroupReferenceFixtureLoadTest extends KernelTestCase
{
    #[Test]
    public function indicationGroupsFixtureLoadsFiveGroupsWithMembers(): void
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

        $fixtures = $loader->getFixtures(['indication_groups']);
        $purger = $purgerFactory->createForEntityManager('default', $entityManager, []);
        $executor = new ORMExecutor($entityManager, $purger);
        $executor->execute($fixtures, true);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM indication_group'));

        $withoutMembers = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM indication_group g
             LEFT JOIN indication_group_indication_normalized gi ON gi.indication_group_id = g.id
             WHERE gi.indication_normalized_id IS NULL',
        );
        self::assertSame(0, $withoutMembers, 'Every fixture group must have at least one indication.');
    }
}
