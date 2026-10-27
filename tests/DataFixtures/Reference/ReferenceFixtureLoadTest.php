<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\DataFixtures\Reference\SyntheticReferenceCatalogBuilder;
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
    public function referenceGroupLoadsTheSyntheticCatalog(): void
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
        /** @var ReferenceCatalogPackageWriter $writer */
        $writer = $container->get(ReferenceCatalogPackageWriter::class);
        $package = new SyntheticReferenceCatalogBuilder($writer)->build();

        foreach (ReferenceCatalogType::importOrder() as $type) {
            $table = $entityManager->getClassMetadata($type->entityClass())->getTableName();
            self::assertSame($package->meta->counts[$type->value], (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table)), $type->value);
        }

        self::assertSame(24, (int) $connection->fetchOne('SELECT COUNT(*) FROM dispatch_area'));
        self::assertSame(208, (int) $connection->fetchOne('SELECT COUNT(*) FROM indication_normalized'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM hospital WHERE latitude IS NULL OR is_participating = true OR owner_id IS NOT NULL'));
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM department_alias'));

        $unlinked = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM indication_normalized n
             LEFT JOIN indication_raw r ON r.normalized_id = n.id
             WHERE r.id IS NULL',
        );
        self::assertSame(0, $unlinked, 'Every normalized indication must have a linked raw row.');
    }
}
