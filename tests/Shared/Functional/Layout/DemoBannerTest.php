<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Layout;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/** The demo banner (ADR 023) appears only when app_demo_mode is on, which only the demo environment sets. */
final class DemoBannerTest extends WebTestCase
{
    public function testLayoutHasNoDemoBannerOutsideTheDemoEnvironment(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#demo-environment-banner');
        self::assertSelectorTextNotContains('title', 'Demo ·');
    }

    public function testBannerSaysThatAllDataIsSynthetic(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);

        $html = $twig->render('@Shared/_includes/demo_banner.html.twig');

        self::assertStringContainsString('id="demo-environment-banner"', $html);
        self::assertStringContainsString('Demo environment.', $html);
        self::assertStringContainsString('All hospitals, users and records shown here are synthetic.', $html);
    }
}
