<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Sync overview path excludes Self-Benchmark (lazy Turbo-Frame).
 * See docs/04-features/statistics/overview-dashboard-performance.md.
 */
#[ResetDatabase]
final class OverviewDashboardQueryCountTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    private const int MAX_SYNC_QUERIES = 30;

    public function testOverviewDashboardUsesBoundedQueryCountWithoutSelfBenchmark(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->enableProfiler();

        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=month&year=2025&month=6');

        $this->assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $queryCount = $collector->getQueryCount();
        self::assertLessThanOrEqual(
            self::MAX_SYNC_QUERIES,
            $queryCount,
            sprintf('Expected at most %d DB queries on overview sync path, got %d.', self::MAX_SYNC_QUERIES, $queryCount),
        );

        $sqlParts = [];
        foreach ($collector->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sqlParts[] = (string) ($query['sql'] ?? '');
            }
        }
        $sqlBlob = strtolower(implode("\n", $sqlParts));
        self::assertStringNotContainsString(' as is_primary', $sqlBlob);
        self::assertStringNotContainsString('as is_comparison', $sqlBlob);
    }
}
