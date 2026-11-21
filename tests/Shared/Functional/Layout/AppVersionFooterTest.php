<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Layout;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;

final class AppVersionFooterTest extends WebTestCase
{
    use ClockSensitiveTrait;

    public function testPublicLayoutShowsFooterMetaLine(): void
    {
        self::mockTime('2031-06-15 12:00:00');
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();

        self::assertSelectorTextSame('[data-testid="footer-meta-copyright"]', '© 2025–2031', 'the end year comes from the clock (ADR 021)');
        self::assertSelectorExists('[data-testid="footer-meta-repository"]');
        self::assertSame(
            'https://github.com/nplhse/collaborative-ivena-statistics',
            $crawler->filter('[data-testid="footer-meta-repository"]')->attr('href'),
        );
        self::assertSelectorTextContains('[data-testid="footer-meta-repository"]', 'collaborative-ivena-statistics');
        self::assertSelectorTextContains('[data-testid="footer-meta-hoster"]', 'Uberspace');
        self::assertSame('https://uberspace.de', $crawler->filter('[data-testid="footer-meta-hoster"] a')->attr('href'));
        self::assertSelectorTextContains('[data-testid="footer-meta-version"]', Kernel::APP_VERSION);
    }

    public function testCopyrightShowsOneYearInTheStartYear(): void
    {
        self::mockTime('2025-06-15 12:00:00');
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');

        self::assertSelectorTextSame('[data-testid="footer-meta-copyright"]', '© 2025');
    }

    public function testUberspaceCreditIsLeftOutWhenTurnedOff(): void
    {
        $_SERVER['APP_UBERSPACE_CREDIT'] = $_ENV['APP_UBERSPACE_CREDIT'] = 'false';

        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="footer-meta-hoster"]');
        self::assertSelectorTextNotContains('footer', 'Uberspace');
        self::assertSelectorExists('[data-testid="footer-meta-version"]');
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER['APP_UBERSPACE_CREDIT'], $_ENV['APP_UBERSPACE_CREDIT']);

        parent::tearDown();
    }
}
