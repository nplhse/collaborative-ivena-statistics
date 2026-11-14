<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\Shared\Infrastructure\Monitoring\Http\BoundedContextResolver;
use App\Shared\Infrastructure\Monitoring\Http\SentryRequestContextSubscriber;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryMonitoringSettings;
use App\Shared\Infrastructure\Monitoring\Sentry\SentryTraceUserPolicy;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zenstruck\Foundry\Test\Factories;

final class SentryRequestContextSubscriberTest extends KernelTestCase
{
    use Factories;

    private string $subject = '';

    private SentryTraceUserPolicy $policy;

    public function testTagsTheGeneratedAndTheUpstreamRequestId(): void
    {
        $context = new AuditContext();
        $context->setRequestId('0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f');
        $context->setUpstreamRequestId('proxy-42');

        $event = $this->eventAfter($context, null, Request::create('/'));

        self::assertSame('0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f', $event->getTags()['request_id'] ?? null);
        self::assertSame('proxy-42', $event->getTags()['upstream_request_id'] ?? null);
    }

    public function testNoUpstreamTagWithoutUpstreamRequestId(): void
    {
        $context = new AuditContext();
        $context->setRequestId('0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f');

        self::assertArrayNotHasKey('upstream_request_id', $this->eventAfter($context, null, Request::create('/'))->getTags());
    }

    public function testTagsRouteAndBoundedContextFromTheResolvedController(): void
    {
        $event = $this->eventAfter(new AuditContext(), null, $this->statisticsRequest());

        self::assertSame('app_stats_dashboard', $event->getTags()['route'] ?? null);
        self::assertSame('Statistics', $event->getTags()['bounded_context'] ?? null);
        self::assertNull($event->getUser());
    }

    public function testErrorsAlwaysCarryTheUserIdButTracesNeedThePerformanceConsent(): void
    {
        foreach ([
            'no decision' => [null, false],
            'necessary only' => [[false, false], false],
            'analytics without performance' => [[true, false], false],
            'analytics with performance' => [[true, true], true],
        ] as $case => [$choice, $tracesMayCarryUser]) {
            $user = null === $choice ? $this->user() : $this->userWithConsent(...$choice);

            $sentryUser = $this->eventAfter(new AuditContext(), $user, $this->statisticsRequest())->getUser();

            self::assertNotNull($sentryUser, $case);
            self::assertSame((string) $user->getId(), $sentryUser->getId(), $case);
            self::assertNull($sentryUser->getUsername(), $case.': only the numeric id');
            self::assertSame($tracesMayCarryUser, $this->policy->isUserAllowed(), $case);
        }
    }

    private function statisticsRequest(): Request
    {
        $request = Request::create('/statistics/');
        $request->cookies->set(CookieConsentService::SUBJECT_COOKIE_NAME, $this->subject);
        $request->attributes->set('_route', 'app_stats_dashboard');
        $request->attributes->set('_controller', 'App\Statistics\UI\Http\Controller\DashboardController::__invoke');

        return $request;
    }

    private function user(): User
    {
        // A fresh browser per user, so the cases of one test do not share a consent.
        $this->subject = 'sentry-context-'.bin2hex(random_bytes(8));

        $proxy = UserFactory::createOne(['username' => 'sentry-context-'.bin2hex(random_bytes(4))]);
        $user = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $proxy->getId());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function userWithConsent(bool $analytics, bool $performance = false): User
    {
        $user = $this->user();
        // Sentry is not configured in tests; this service offers the performance option.
        $service = new CookieConsentService(
            self::getContainer()->get(CookieConsentRepository::class),
            self::getContainer()->get(ClockInterface::class),
            '',
            new SentryMonitoringSettings('https://key@o1.ingest.de.sentry.io/2'),
        );
        $request = Request::create('/');
        $request->cookies->set(CookieConsentService::SUBJECT_COOKIE_NAME, $this->subject);
        $service->applyPreference($service->resolveForRequest($request, $user), $analytics, $user, $performance);

        return $user;
    }

    private function eventAfter(AuditContext $context, ?User $user, Request $request): Event
    {
        $hub = new Hub(ClientBuilder::create()->getClient());
        $this->policy = new SentryTraceUserPolicy();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $subscriber = new SentryRequestContextSubscriber(
            $hub,
            $security,
            $context,
            new BoundedContextResolver(),
            self::getContainer()->get(CookieConsentService::class),
            $this->policy,
        );

        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelController(new ControllerEvent($kernel, static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST));

        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event) ?? $event;
        });

        return $event;
    }
}
