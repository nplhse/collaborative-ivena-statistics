<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Demo;

use App\Analytics\Infrastructure\Http\AnalyticsCookieManager;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\Shared\Infrastructure\Locale\LocaleCookieManager;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie names of the application (ADR 023): unchanged outside the demo environment, prefixed with
 * app.cookie_prefix ("demo_") in it, so a secure dev cookie on the same host cannot block the demo.
 */
final class CookieNamesTest extends KernelTestCase
{
    public function testDefaultCookieNamesStayUnchanged(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame('', $container->getParameter('app.cookie_prefix'));
        self::assertSame('consent_subject_id', $container->get(CookieConsentService::class)->subjectCookieName());
        self::assertSame('APP_LOCALE', $container->get(LocaleCookieManager::class)->cookieName());
        self::assertSame(
            ['analytics_session', 'analytics_visitor'],
            $this->analyticsCookieNames($container->get(AnalyticsCookieManager::class)),
        );
    }

    public function testPrefixIsAddedToEveryApplicationCookie(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $clock = $container->get(ClockInterface::class);

        $consent = new CookieConsentService($container->get(CookieConsentRepository::class), $clock, 'demo_');
        self::assertSame('demo_consent_subject_id', $consent->subjectCookieName());
        self::assertSame('demo_consent_subject_id', $consent->buildSubjectCookieForSubjectId(Request::create('/'), 'subject')->getName());

        $locale = new LocaleCookieManager($clock, 'demo_');
        self::assertSame('demo_APP_LOCALE', $locale->buildCookie(Request::create('/'), 'de')->getName());
        self::assertSame('de', $locale->readFromRequest(Request::create('/', cookies: ['demo_APP_LOCALE' => 'de', 'APP_LOCALE' => 'en'])));

        self::assertSame(
            ['demo_analytics_session', 'demo_analytics_visitor'],
            $this->analyticsCookieNames(new AnalyticsCookieManager($clock, 'demo_')),
        );
    }

    /** @return list<string> */
    private function analyticsCookieNames(AnalyticsCookieManager $manager): array
    {
        $response = new Response();
        $manager->applyCookies(Request::create('/'), $response, true);

        $names = array_map(static fn (Cookie $cookie): string => $cookie->getName(), $response->headers->getCookies());
        sort($names);

        return $names;
    }
}
