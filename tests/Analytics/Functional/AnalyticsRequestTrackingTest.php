<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Functional;

use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Http\AnalyticsCookieManager;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
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
            self::assertStringNotContainsString('usage-analytics/filters', $html);
            self::assertStringNotContainsString('usage-analytics/adoption', $html);
            self::assertStringNotContainsString('usage-analytics/journeys', $html);
        }

        // The filter usage page was removed (#988).
        $client->request(Request::METHOD_GET, '/admin/operations/usage-analytics/filters');
        self::assertResponseStatusCodeSame(404);

        // The adoption and journey pages were removed (#989).
        foreach (['adoption', 'journeys'] as $removedPage) {
            $client->request(Request::METHOD_GET, '/admin/operations/usage-analytics/'.$removedPage);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testAdminAreaRequestsAreNotTracked(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'analytics-skip-admin-'.bin2hex(random_bytes(4)),
        ]);
        $client->loginUser($admin);

        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $beforeAdminRows = $repository->count(['featureArea' => FeatureArea::Admin]);

        $client->request(Request::METHOD_GET, '/admin/operations/usage-analytics/overview');
        self::assertResponseIsSuccessful();

        self::assertSame(
            $beforeAdminRows,
            $repository->count(['featureArea' => FeatureArea::Admin]),
            'Admin area requests must not create analytics_request rows.',
        );

        $appAdminRows = $repository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.routeName LIKE :prefix')
            ->setParameter('prefix', 'app_admin_%')
            ->getQuery()
            ->getSingleScalarResult()
        ;
        self::assertSame(0, (int) $appAdminRows);
    }

    public function testAdminHomeRequestIsStillTracked(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->asAdmin()->create([
            'username' => 'analytics-admin-home-'.bin2hex(random_bytes(4)),
        ]);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        /** @var list<AnalyticsRequest> $rows */
        $rows = $repository->findBy(['routeName' => 'app_default'], ['id' => 'DESC'], 5);
        self::assertNotEmpty($rows);
        self::assertSame(FeatureArea::Home, $rows[0]->getFeatureArea());
        self::assertSame(UserRole::ADMIN, $rows[0]->getUserRole());
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('provideCountedAccessRequests')]
    public function testRequestsAreClassifiedAsCountedAccess(string $method, string $path, array $server, bool $expected): void
    {
        $client = self::createClient();
        $client->request($method, $path, server: $server);

        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        /** @var list<AnalyticsRequest> $rows */
        $rows = $repository->findBy([], ['id' => 'DESC'], 1);
        self::assertCount(1, $rows);
        self::assertSame($expected, $rows[0]->isCountedAccess());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, string>, 3: bool}>
     */
    public static function provideCountedAccessRequests(): iterable
    {
        $browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/130.0 Safari/537.36'];

        yield 'page view' => [Request::METHOD_GET, '/', $browser, true];
        yield 'turbo frame request' => [Request::METHOD_GET, '/', $browser + ['HTTP_TURBO_FRAME' => 'content'], false];
        yield 'turbo prefetch' => [Request::METHOD_GET, '/', $browser + ['HTTP_X_SEC_PURPOSE' => 'prefetch'], false];
        yield 'browser prefetch' => [Request::METHOD_GET, '/', $browser + ['HTTP_SEC_PURPOSE' => 'prefetch;prerender'], false];
        yield 'xhr' => [Request::METHOD_GET, '/', $browser + ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], false];
        yield 'bot' => [Request::METHOD_GET, '/', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'], false];
        yield 'robots.txt' => [Request::METHOD_GET, '/robots.txt', $browser, false];
        yield 'not found' => [Request::METHOD_GET, '/this-page-does-not-exist-950', $browser, false];
    }
}
