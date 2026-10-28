<?php

declare(strict_types=1);

namespace App\Tests\Admin\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/** The EasyAdmin layout override shows the demo banner (ADR 023) only when app_demo_mode is on. */
final class AdminDemoBannerTest extends KernelTestCase
{
    public function testAdminLayoutShowsBannerInDemoMode(): void
    {
        $html = $this->renderFlashMessagesBlock(demoMode: true);

        self::assertStringContainsString('id="demo-environment-banner"', $html);
        self::assertStringContainsString('All hospitals, users and records shown here are synthetic.', $html);
    }

    public function testAdminLayoutHasNoBannerOutsideDemoMode(): void
    {
        self::assertStringNotContainsString('demo-environment-banner', $this->renderFlashMessagesBlock(demoMode: false));
    }

    private function renderFlashMessagesBlock(bool $demoMode): string
    {
        self::bootKernel();

        $request = Request::create('/admin');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $twig = self::getContainer()->get(Environment::class);
        // Globals can be replaced until the first template renders; the demo environment sets this one through app.demo_mode.
        $twig->addGlobal('app_demo_mode', $demoMode);

        return $twig->load('@EasyAdmin/layout.html.twig')->renderBlock('flash_messages', [
            'ea' => new class {
                public function templatePath(string $name): string
                {
                    return '@EasyAdmin/'.$name.'.html.twig';
                }
            },
        ]);
    }
}
