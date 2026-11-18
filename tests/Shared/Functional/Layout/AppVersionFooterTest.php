<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Layout;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AppVersionFooterTest extends WebTestCase
{
    public function testPublicLayoutShowsFooterMetaLine(): void
    {
        $client = self::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();

        $copyrightYear = new \DateTimeImmutable()->format('Y');
        $expectedCopyright = 2025 === (int) $copyrightYear
            ? '© 2025'
            : sprintf('© 2025–%s', $copyrightYear);

        self::assertSelectorTextContains('[data-testid="footer-meta-copyright"]', $expectedCopyright);
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
