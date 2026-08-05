<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Functional;

use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Infrastructure\Http\AnalyticsCookieManager;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalyticsRequestTrackingTest extends WebTestCase
{
    use Factories;

    public function testHomeRequestIsTrackedWithoutKeysWhenAnalyticsDeclined(): void
    {
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="cookie_consent_banner[_token]"]')->first()->attr('value');
        self::assertIsString($token);

        $client->request(Request::METHOD_POST, '/cookies/banner', [
            'cookie_consent_banner' => [
                '_token' => $token,
                'target' => '/',
                'essential' => '1',
            ],
        ]);
        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $cookies = $client->getResponse()->headers->all('Set-Cookie') ?? [];
        self::assertFalse(
            array_any($cookies, static fn (string $c): bool => str_contains($c, AnalyticsCookieManager::VISITOR_COOKIE.'=')),
        );

        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        /** @var list<AnalyticsRequest> $rows */
        $rows = $repository->findBy(['routeName' => 'app_default'], ['id' => 'DESC'], 5);
        self::assertNotEmpty($rows);

        $latest = $rows[0];
        self::assertNull($latest->getVisitorKey());
        self::assertNull($latest->getSessionKey());
        self::assertNull($latest->getAnalyticsUserKey());
        self::assertSame('home', $latest->getFeatureArea()->value);
    }

    public function testAcceptAllSetsAnalyticsCookiesAndStoresKeys(): void
    {
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="cookie_consent_banner[_token]"]')->first()->attr('value');
        self::assertIsString($token);

        $client->request(Request::METHOD_POST, '/cookies/banner', [
            'cookie_consent_banner' => [
                '_token' => $token,
                'target' => '/',
                'all' => '1',
            ],
        ]);
        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $cookieJar = $client->getCookieJar();
        self::assertNotNull($cookieJar->get(AnalyticsCookieManager::VISITOR_COOKIE));
        self::assertNotNull($cookieJar->get(AnalyticsCookieManager::SESSION_COOKIE));

        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        /** @var list<AnalyticsRequest> $rows */
        $rows = $repository->findBy(['routeName' => 'app_default'], ['id' => 'DESC'], 5);
        self::assertNotEmpty($rows);

        $withKeys = null;
        foreach ($rows as $row) {
            if (null !== $row->getVisitorKey()) {
                $withKeys = $row;
                break;
            }
        }

        self::assertNotNull($withKeys);
        self::assertNotNull($withKeys->getSessionKey());
        self::assertNull($withKeys->getAnalyticsUserKey());
    }

    public function testAdminUsageAnalyticsPagesShowSectionAggregatesWithoutUserIds(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'analytics-admin-'.bin2hex(random_bytes(4)),
        ]);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/operations/usage-analytics');
        self::assertResponseRedirects('/admin/operations/usage-analytics/overview');

        $pages = [
            '/admin/operations/usage-analytics/overview' => ['Usage analytics — Overview', 'Requests today', 'Feature areas'],
            '/admin/operations/usage-analytics/adoption' => ['Usage analytics — Adoption', 'Top usage events', 'Engagement depth'],
            '/admin/operations/usage-analytics/journeys' => ['Usage analytics — Journeys', 'Onboarding funnel', 'Time to first value', 'Navigation'],
            '/admin/operations/usage-analytics/filters' => ['Usage analytics — Filters', 'filter parameter'],
            '/admin/operations/usage-analytics/performance' => ['Usage analytics — Performance', 'By feature area', 'Slowest routes'],
        ];

        foreach ($pages as $path => $needles) {
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $html, sprintf('Missing "%s" on %s', $needle, $path));
            }
            self::assertStringNotContainsString('user_id', $html);
            self::assertStringNotContainsString('User ID', $html);
        }
    }
}
