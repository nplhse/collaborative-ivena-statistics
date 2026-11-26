<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Purger;

use App\DataFixtures\Purger\FixturePurgeParticipantInterface;
use App\DataFixtures\Purger\MaterializedViewAwareOrmPurgerFactory;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewDropper;
use App\Statistics\Infrastructure\Query\Overview\OverviewMaterializedViewsInstaller;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A plugin takes part in the purge of a fixture load (#1300): the purge leaves its tables alone and calls it
 * afterwards. A participant keeps `analytics_aggregation_run`; `job_run` is purged as usual.
 */
#[ResetDatabase]
final class FixturePurgeParticipantTest extends KernelTestCase
{
    public function testThePurgeKeepsTheParticipantsTablesAndCallsItAfterwards(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("INSERT INTO analytics_aggregation_run (date, aggregated_at, raw_request_count, aggregate_rows_written) VALUES ('2026-11-01', '2026-11-02 03:00:00', 1, 1)");
        $connection->executeStatement("INSERT INTO job_run (id, job, triggered_by, status, started_at) VALUES ('purged', 'test', 'test', 'succeeded', '2026-11-02 03:00:00')");

        $participant = new class implements FixturePurgeParticipantInterface {
            public int $calls = 0;

            #[\Override]
            public function keptTables(): array
            {
                return ['analytics_aggregation_run'];
            }

            #[\Override]
            public function afterPurge(): void
            {
                ++$this->calls;
            }
        };

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $factory = new MaterializedViewAwareOrmPurgerFactory(
            new ORMPurgerFactory(),
            $this->service(StatisticsMaterializedViewDropper::class),
            $this->service(OverviewMaterializedViewsInstaller::class),
            [$participant],
        );
        $factory->createForEntityManager(null, $em)->purge();

        self::assertSame(1, $participant->calls);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM analytics_aggregation_run'), 'The participant keeps its table.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM job_run'), 'Other tables are purged.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
