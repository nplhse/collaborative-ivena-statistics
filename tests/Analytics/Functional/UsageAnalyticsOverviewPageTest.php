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
final class UsageAnalyticsOverviewPageTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string PATH = '/admin/operations/usage-analytics/overview';

    public function testShowsTranslatedTilesWithFormattedNumbersForAGermanAdmin(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-10 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $client = self::createClient();
        // 1234 raw requests today, anonymous and without session: they count for today and the last 7 days.
        self::getContainer()->get(Connection::class)->executeStatement(<<<'SQL'
            INSERT INTO analytics_request (occurred_at, route_name, feature_area, http_status, is_authenticated, browser_family, device_type)
            SELECT '2026-11-10 10:00:00', 'app_default', 'home', 200, false, 'Other', 'Desktop'
            FROM generate_series(1, 1234)
            SQL);
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, self::PATH);

        self::assertResponseIsSuccessful();
        $tiles = $crawler->filter('.card-body')->each(static fn ($node): string => trim((string) preg_replace('/\s+/', ' ', $node->text())));
        self::assertContains('Anfragen heute 1.234', $tiles);
        self::assertContains('Anfragen (7 Tage) 1.234', $tiles);
        self::assertContains('DAU / WAU / MAU 0 / 0 / 0', $tiles);
        self::assertContains('Sitzungen (7 Tage) 0', $tiles);
        self::assertStringNotContainsString('Requests today', $crawler->text());
    }

    public function testShowsGermanHeadingsAndEmptyStatesForAGermanAdmin(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => 'de']));

        $crawler = $client->request(Request::METHOD_GET, self::PATH);

        self::assertResponseIsSuccessful();
        $text = static fn (string $selector): array => $crawler->filter($selector)->each(static fn ($node): string => trim($node->text()));
        self::assertContains('Nutzungsanalyse — Übersicht', $text('h1'));
        self::assertSame(['Funktionsbereiche (7 Tage)', 'Anmeldung (7 Tage)', 'Häufigste Routen (7 Tage)'], $text('.card-header'));
        self::assertSame(['Bereich', 'Anfragen', 'Anteil', 'Route', 'Anfragen'], $text('thead th'));
        self::assertSame(['Angemeldet', 'Anonym', 'Anfragen (30 Tage)'], $text('dt'));
        self::assertSame(['Noch keine Daten.', 'Noch keine Daten.'], $text('tbody td.text-secondary'));
        foreach (['Feature areas', 'Authentication', 'Top routes', 'No data yet.', 'Usage analytics'] as $english) {
            self::assertStringNotContainsString($english, $crawler->filter('main, #main, body')->first()->text());
        }
    }
}
