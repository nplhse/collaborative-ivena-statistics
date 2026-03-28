<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Audit;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\State;
use App\Shared\Infrastructure\Audit\AuditContext;
use PHPUnit\Framework\TestCase;

final class AuditContextTest extends TestCase
{
    public function testIntentStackBeginAndEnd(): void
    {
        $ctx = new AuditContext();

        self::assertNull($ctx->getCurrentIntent());

        $ctx->beginIntent('a', ['k' => 1]);
        self::assertSame('a', $ctx->getCurrentIntent()['name']);
        self::assertSame(['k' => 1], $ctx->getCurrentIntent()['metadata']);

        $ctx->beginIntent('b', []);
        self::assertSame('b', $ctx->getCurrentIntent()['name']);

        $ctx->endIntent();
        self::assertSame('a', $ctx->getCurrentIntent()['name']);

        $ctx->endIntent();
        self::assertNull($ctx->getCurrentIntent());
    }

    public function testSuppressedEntityAuditPushPopAndNestedScopes(): void
    {
        $ctx = new AuditContext();

        self::assertFalse($ctx->isEntityAuditSuppressed(Allocation::class));

        $ctx->pushSuppressedEntityAudit([Allocation::class]);
        self::assertTrue($ctx->isEntityAuditSuppressed(Allocation::class));
        self::assertFalse($ctx->isEntityAuditSuppressed(State::class));

        $ctx->pushSuppressedEntityAudit([State::class]);
        self::assertTrue($ctx->isEntityAuditSuppressed(Allocation::class));
        self::assertTrue($ctx->isEntityAuditSuppressed(State::class));

        $ctx->popSuppressedEntityAudit();
        self::assertTrue($ctx->isEntityAuditSuppressed(Allocation::class));
        self::assertFalse($ctx->isEntityAuditSuppressed(State::class));

        $ctx->popSuppressedEntityAudit();
        self::assertFalse($ctx->isEntityAuditSuppressed(Allocation::class));
    }

    public function testResetClearsAllState(): void
    {
        $ctx = new AuditContext();
        $ctx->setRequestId('rid');
        $ctx->setOrigin(AuditContext::ORIGIN_HTTP);
        $ctx->setActorUserId(42);
        $ctx->setClientIp('1.2.3.4');
        $ctx->setUserAgent('ua');
        $ctx->beginIntent('x', []);
        $ctx->pushSuppressedEntityAudit([Allocation::class]);

        $ctx->reset();

        self::assertNull($ctx->getRequestId());
        self::assertNull($ctx->getOrigin());
        self::assertNull($ctx->getActorUserId());
        self::assertNull($ctx->getClientIp());
        self::assertNull($ctx->getUserAgent());
        self::assertNull($ctx->getCurrentIntent());
        self::assertFalse($ctx->isEntityAuditSuppressed(Allocation::class));
    }

    public function testEnsureRequestIdGeneratesOnce(): void
    {
        $ctx = new AuditContext();
        $id = $ctx->ensureRequestId(static fn (): string => 'generated-once');
        self::assertSame('generated-once', $id);
        self::assertSame('generated-once', $ctx->ensureRequestId(static fn (): string => 'ignored'));
    }
}
