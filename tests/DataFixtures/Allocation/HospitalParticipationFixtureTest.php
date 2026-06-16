<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Allocation;

use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

final class HospitalParticipationFixtureTest extends KernelTestCase
{
    use ResetDatabase;

    #[Test]
    public function participationGroupAssignsOwnersAndAssociateGrant(): void
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

        $fixtures = $loader->getFixtures(['participation']);
        $purger = $purgerFactory->createForEntityManager('default', $entityManager, []);
        $executor = new ORMExecutor($entityManager, $purger);
        $executor->execute($fixtures, true);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(26, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM hospital WHERE is_participating = true AND owner_id IS NOT NULL',
        ));
        self::assertSame(51, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM hospital WHERE is_participating = false AND owner_id IS NULL',
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM hospital h
             INNER JOIN "user" u ON u.id = h.owner_id
             WHERE h.is_participating = true AND u.username = :username',
            ['username' => 'foo'],
        ));
        self::assertSame(
            'Helios Dr. Horst-Schmidt-Kliniken Wiesbaden',
            $connection->fetchOne(
                'SELECT h.name FROM hospital h
                 INNER JOIN "user" u ON u.id = h.owner_id
                 WHERE u.username = :username',
                ['username' => 'foo'],
            ),
        );
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM hospital_access_grant g
             INNER JOIN "user" owner ON owner.id = g.created_by_id
             INNER JOIN "user" grantee ON grantee.id = g.user_id
             WHERE owner.username = :owner AND grantee.username = :grantee AND g.permissions = :permissions',
            [
                'owner' => 'foo',
                'grantee' => 'associate',
                'permissions' => 15,
            ],
        ));
    }
}
