<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Functional;

use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AccessOverviewPageTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string PATH = '/admin/operations/usage-analytics/accesses';

    public function testShowsTotalsCountingStartAndPeriods(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-10 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        // Counting started on 2026-10-29: 4 + 1 in October, 2 + 3 in November = 10 accesses.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO analytics_access_daily (date, is_authenticated, access_count) VALUES
                ('2026-10-29', true, 4), ('2026-10-29', false, 1),
                ('2026-11-02', true, 2), ('2026-11-02', false, 3)
            SQL);
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSame('10', trim($crawler->filter('[data-testid="accesses-total"]')->text()));
        self::assertStringContainsString('Oct 29, 2026', $crawler->filter('[data-testid="accesses-counted-since"]')->text());
        self::assertStringContainsString('not visitors or sessions', $crawler->filter('main, body')->first()->text());

        $months = $crawler->filter('[data-testid="accesses-months"] tbody tr');
        self::assertCount(2, $months);
        // Newest first: November is not over yet, October started with the counting.
        self::assertStringContainsString('November 2026', $months->eq(0)->text());
        self::assertStringContainsString('Not over yet', $months->eq(0)->text());
        self::assertStringContainsString('October 2026', $months->eq(1)->text());
        self::assertStringContainsString('Counted from Oct 29, 2026', $months->eq(1)->text());

        $years = $crawler->filter('[data-testid="accesses-years"] tbody tr');
        self::assertCount(1, $years);
        self::assertStringContainsString('2026', $years->eq(0)->text());
        self::assertStringContainsString('10', $years->eq(0)->text());
    }

    public function testShowsEmptyStateWhenNothingWasCounted(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $client->request(Request::METHOD_GET, self::PATH);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="accesses-empty"]', 'No accesses counted yet.');
    }

    public function testIsOnlyAvailableToAdmins(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->create(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        $client->request(Request::METHOD_GET, self::PATH);

        self::assertResponseStatusCodeSame(403);
    }
}
