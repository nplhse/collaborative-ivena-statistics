<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring\Http;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Monitoring\Http\BoundedContextResolver;
use App\Shared\Infrastructure\Monitoring\Http\SentryRequestContextSubscriber;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SentryRequestContextSubscriberTest extends TestCase
{
    public function testTagsTheGeneratedAndTheUpstreamRequestId(): void
    {
        $context = new AuditContext();
        $context->setRequestId('0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f');
        $context->setUpstreamRequestId('proxy-42');

        $tags = $this->tagsAfterRequest($context);

        self::assertSame('0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f', $tags['request_id'] ?? null);
        self::assertSame('proxy-42', $tags['upstream_request_id'] ?? null);
    }

    public function testNoUpstreamTagWithoutUpstreamRequestId(): void
    {
        $context = new AuditContext();
        $context->setRequestId('0193a4b2-6f1e-7c55-9d3e-2a1b0c9d8e7f');

        self::assertArrayNotHasKey('upstream_request_id', $this->tagsAfterRequest($context));
    }

    /**
     * @return array<string, string>
     */
    private function tagsAfterRequest(AuditContext $context): array
    {
        $hub = new Hub($this->createStub(ClientInterface::class));
        $subscriber = new SentryRequestContextSubscriber(
            $hub,
            $this->createStub(Security::class),
            $context,
            new BoundedContextResolver(),
        );

        $subscriber->onKernelRequest(new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
        ));

        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event) ?? $event;
        });

        return $event->getTags();
    }
}
