<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Http;

use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Infrastructure\Http\AnalyticsCookieManager;
use App\Analytics\Infrastructure\Http\AnalyticsRequestSubscriber;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The terminate listener reuses the analytics consent resolved on kernel.response instead of
 * querying the consent table again after the EntityManager clear (#980).
 */
#[ResetDatabase]
final class AnalyticsRequestSubscriberTest extends KernelTestCase
{
    private const string SUBJECT_ID = 'analytics-subscriber-subject-0001';

    public function testTerminateReusesConsentResolvedOnResponse(): void
    {
        self::bootKernel();
        $this->storeAnalyticsConsent(true);

        $subscriber = self::getContainer()->get(AnalyticsRequestSubscriber::class);
        $request = $this->createTrackedRequest();
        $response = new Response();

        $subscriber->onKernelRequest(new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelResponse(new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        // Withdraw the consent in the database between response and terminate. A second lookup
        // would see the withdrawal and record the request without keys.
        $this->storeAnalyticsConsent(false);

        $subscriber->onKernelTerminate(new TerminateEvent(self::$kernel, $request, $response));

        $recorded = $this->latestRecordedRequest();
        self::assertSame($this->visitorCookieValue($response), $recorded->getVisitorKey());
        self::assertNotNull($recorded->getSessionKey());
    }

    public function testTerminateResolvesConsentWhenResponsePhaseDidNotRun(): void
    {
        self::bootKernel();
        $this->storeAnalyticsConsent(false);

        $subscriber = self::getContainer()->get(AnalyticsRequestSubscriber::class);
        $request = $this->createTrackedRequest();

        $subscriber->onKernelRequest(new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelTerminate(new TerminateEvent(self::$kernel, $request, new Response()));

        $recorded = $this->latestRecordedRequest();
        self::assertNull($recorded->getVisitorKey());
        self::assertNull($recorded->getSessionKey());
    }

    private function createTrackedRequest(): Request
    {
        $consentService = self::getContainer()->get(CookieConsentService::class);
        $request = Request::create('/');
        $request->attributes->set('_route', 'app_default');
        $request->cookies->set($consentService->subjectCookieName(), self::SUBJECT_ID);

        return $request;
    }

    private function storeAnalyticsConsent(bool $analytics): void
    {
        $consentService = self::getContainer()->get(CookieConsentService::class);
        $request = Request::create('/');
        $request->cookies->set($consentService->subjectCookieName(), self::SUBJECT_ID);

        $consent = $consentService->resolveForRequest($request, null);
        $consentService->applyPreference($consent, $analytics, null);
    }

    private function latestRecordedRequest(): AnalyticsRequest
    {
        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        $rows = $repository->findBy(['routeName' => 'app_default'], ['id' => 'DESC'], 1);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function visitorCookieValue(Response $response): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (AnalyticsCookieManager::VISITOR_COOKIE === $cookie->getName()) {
                $value = $cookie->getValue();
                self::assertIsString($value);

                return $value;
            }
        }

        self::fail('The response sets no analytics visitor cookie.');
    }
}
