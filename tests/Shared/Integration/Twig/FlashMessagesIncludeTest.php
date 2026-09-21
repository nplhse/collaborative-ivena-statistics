<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class FlashMessagesIncludeTest extends KernelTestCase
{
    public function testRendersFlashedErrorTextInsideAlert(): void
    {
        self::bootKernel();

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->getFlashBag()->add('error', 'Please select a normalized indication.');

        $request = Request::create('/');
        $request->setSession($session);
        $request->cookies->set($session->getName(), $session->getId());

        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->render('@Shared/_includes/flash_messages.html.twig');

        self::assertStringContainsString('alert-danger', $html);
        self::assertStringContainsString('Please select a normalized indication.', $html);
    }
}
