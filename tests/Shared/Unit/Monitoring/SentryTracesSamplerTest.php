<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\Sentry\SentryTracesSampler;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildSentryProfile;
use PHPUnit\Framework\TestCase;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;

final class SentryTracesSamplerTest extends TestCase
{
    public function testJobRunOpsAreSampledCompletely(): void
    {
        self::assertSame(1.0, new SentryTracesSampler(0.2, [new ClosureRebuildSentryProfile()])($this->context('closure.rebuild')));
        self::assertSame(0.2, new SentryTracesSampler(0.2)($this->context('closure.rebuild')), 'Only ops of a registered profile.');
    }

    public function testOtherTransactionsKeepTheGlobalRate(): void
    {
        self::assertSame(0.2, new SentryTracesSampler(0.2)($this->context('console.command')));
        self::assertSame(0.0, new SentryTracesSampler(0.0)($this->context('http.server')));
    }

    public function testAParentDecisionWins(): void
    {
        $context = $this->context('http.server');
        $context->setParentSampled(true);
        self::assertSame(1.0, new SentryTracesSampler(0.0)($context));
        $context->setParentSampled(false);
        self::assertSame(0.0, new SentryTracesSampler(1.0)($context));
    }

    private function context(string $op): SamplingContext
    {
        return SamplingContext::getDefault(TransactionContext::make()->setOp($op));
    }
}
