<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\UsageEvents;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Application\UsageEvents\UsageEventContext;
use App\Analytics\Application\UsageEvents\UsageEventContextResolverInterface;
use App\Analytics\Application\UsageEvents\UsageEventRecorderInterface;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UsageAnalyticsTest extends TestCase
{
    public function testRecordIsSkippedWithoutConsent(): void
    {
        $resolver = new class implements UsageEventContextResolverInterface {
            public function resolveFromRequest(): UsageEventContext
            {
                return UsageEventContext::denied();
            }

            public function resolveForUser(User $user): UsageEventContext
            {
                return UsageEventContext::denied();
            }
        };

        $recorder = new class implements UsageEventRecorderInterface {
            public int $calls = 0;

            public function record(
                string $eventName,
                ?FeatureArea $featureArea = null,
                ?string $analyticsUserKey = null,
                ?string $visitorKey = null,
                ?string $sessionKey = null,
                array $context = [],
            ): void {
                ++$this->calls;
            }
        };

        $analytics = new UsageAnalytics($resolver, $recorder, new NullLogger());
        $analytics->record(UsageEventName::ANALYSIS_EXPLORER_RUN, FeatureArea::Analysis);

        self::assertSame(0, $recorder->calls);
    }

    public function testRecordPersistsWithRoleContext(): void
    {
        $resolver = new class implements UsageEventContextResolverInterface {
            public function resolveFromRequest(): UsageEventContext
            {
                return new UsageEventContext(
                    allowed: true,
                    analyticsUserKey: 'abc',
                    visitorKey: 'v1',
                    sessionKey: 's1',
                    userRole: 'ROLE_PARTICIPANT',
                );
            }

            public function resolveForUser(User $user): UsageEventContext
            {
                return UsageEventContext::denied();
            }
        };

        $recorder = new class implements UsageEventRecorderInterface {
            /** @var list<array{eventName: string, featureArea: ?FeatureArea, analyticsUserKey: ?string, visitorKey: ?string, sessionKey: ?string, context: array<string, scalar|null>}> */
            public array $calls = [];

            public function record(
                string $eventName,
                ?FeatureArea $featureArea = null,
                ?string $analyticsUserKey = null,
                ?string $visitorKey = null,
                ?string $sessionKey = null,
                array $context = [],
            ): void {
                $this->calls[] = [
                    'eventName' => $eventName,
                    'featureArea' => $featureArea,
                    'analyticsUserKey' => $analyticsUserKey,
                    'visitorKey' => $visitorKey,
                    'sessionKey' => $sessionKey,
                    'context' => $context,
                ];
            }
        };

        $analytics = new UsageAnalytics($resolver, $recorder, new NullLogger());
        $analytics->record(UsageEventName::ANALYSIS_EXPLORER_RUN, FeatureArea::Analysis);

        self::assertCount(1, $recorder->calls);
        self::assertSame(UsageEventName::ANALYSIS_EXPLORER_RUN, $recorder->calls[0]['eventName']);
        self::assertSame(FeatureArea::Analysis, $recorder->calls[0]['featureArea']);
        self::assertSame('abc', $recorder->calls[0]['analyticsUserKey']);
        self::assertSame('v1', $recorder->calls[0]['visitorKey']);
        self::assertSame('s1', $recorder->calls[0]['sessionKey']);
        self::assertSame(['user_role' => 'ROLE_PARTICIPANT'], $recorder->calls[0]['context']);
    }
}
