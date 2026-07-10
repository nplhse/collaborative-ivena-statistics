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
        self::assertSelectorTextContains('[data-testid="footer-meta-version"]', Kernel::APP_VERSION);
    }
}
