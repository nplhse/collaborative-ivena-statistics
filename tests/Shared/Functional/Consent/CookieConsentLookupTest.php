<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Consent;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * When the cookie consent reads the database (#1685): only for requests that carry the consent cookie, and never on
 * the stateless health check, so both keep working while the database is down.
 */
#[ResetDatabase]
final class CookieConsentLookupTest extends WebTestCase
{
    use CookieConsentTestHelper;

    public function testAVisitorWithoutTheConsentCookieCausesNoLookup(): void
    {
        $client = self::createClient();

        $this->requestWithProfiler($client, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#cookie-consent-banner');
        self::assertSame(0, $this->consentQueries($client));
    }

    public function testAVisitorWithTheConsentCookieGetsTheStoredDecision(): void
    {
        $client = self::createClient();
        $this->acceptEssentialCookiesOnly($client);

        $this->requestWithProfiler($client, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#cookie-consent-banner');
        self::assertGreaterThanOrEqual(1, $this->consentQueries($client));
    }

    public function testTheHealthCheckSkipsTheConsentAndSetsNoCookie(): void
    {
        $client = self::createClient();
        $this->acceptEssentialCookiesOnly($client);

        $this->requestWithProfiler($client, '/health');

        self::assertSame(0, $this->consentQueries($client), 'not even with the consent cookie');
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    private function requestWithProfiler(KernelBrowser $client, string $path): void
    {
        $client->getContainer()->get('doctrine.debug_data_holder')->reset();
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, $path);
    }

    private function consentQueries(KernelBrowser $client): int
    {
        $profile = $client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $count = 0;
        foreach ($collector->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                if (str_contains(strtolower((string) ($query['sql'] ?? '')), 'cookie_consent')) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
