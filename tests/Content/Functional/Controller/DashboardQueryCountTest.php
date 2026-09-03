<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DashboardQueryCountTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testInitialDashboardDoesNotScanAllocationTablesWhenCacheIsWarm(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $sqlParts = [];
        foreach ($collector->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sqlParts[] = (string) ($query['sql'] ?? '');
            }
        }
        $sqlBlob = strtolower(implode("\n", $sqlParts));

        self::assertStringNotContainsString('allocation_stats_projection', $sqlBlob);
        self::assertDoesNotMatchRegularExpression('/\\bfrom\\s+allocation\\b/', $sqlBlob);
        self::assertDoesNotMatchRegularExpression('/\\bfrom\\s+"allocation"\\b/', $sqlBlob);
        self::assertStringNotContainsString('user_activity', $sqlBlob);
    }

    public function testActivityEndpointQueriesUserActivity(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/dashboard/activity');

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $sqlParts = [];
        foreach ($collector->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sqlParts[] = (string) ($query['sql'] ?? '');
            }
        }
        $sqlBlob = strtolower(implode("\n", $sqlParts));
        self::assertStringContainsString('user_activity', $sqlBlob);
        self::assertStringNotContainsString('allocation_stats_projection', $sqlBlob);
    }

    public function testActivityTimelineQueriesUserActivityOnce(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/activity');

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotNull($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $activityQueries = 0;
        foreach ($collector->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sql = strtolower((string) ($query['sql'] ?? ''));
                if (str_contains($sql, 'user_activity')) {
                    ++$activityQueries;
                }
            }
        }

        self::assertSame(1, $activityQueries);
    }
}
