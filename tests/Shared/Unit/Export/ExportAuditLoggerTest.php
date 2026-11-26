<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Export;

use App\Shared\Application\Export\ExportAuditLogger;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ExportAuditLoggerTest extends TestCase
{
    public function testWritesUpstreamRequestIdAndSessionRefButNoClientData(): void
    {
        $context = new AuditContext();
        $context->setRequestId('rid');
        $context->setOrigin(AuditContext::ORIGIN_HTTP);
        $context->setUpstreamRequestId('proxy-42');
        $context->setSessionRef('0193a4b2-6f1e-4c55-9d3e-2a1b0c9d8e7f');
        $context->setClientIp('1.2.3.4');
        $context->setUserAgent('ua');

        $entry = $this->log($context);

        $metadata = $entry->getMetadata();
        self::assertIsArray($metadata);
        self::assertSame('proxy-42', $metadata['upstreamRequestId'] ?? null);
        self::assertArrayNotHasKey('clientIp', $metadata);
        self::assertArrayNotHasKey('userAgent', $metadata);
        self::assertSame('0193a4b2-6f1e-4c55-9d3e-2a1b0c9d8e7f', $entry->getSessionRef());
        self::assertSame(AuditEntry::OUTCOME_SUCCESS, $entry->getOutcome());
    }

    public function testWithoutUpstreamRequestIdTheKeyIsMissing(): void
    {
        $context = new AuditContext();
        $context->setRequestId('rid');

        $entry = $this->log($context);

        self::assertArrayNotHasKey('upstreamRequestId', $entry->getMetadata() ?? []);
        self::assertNull($entry->getSessionRef());
    }

    private function log(AuditContext $context): AuditEntry
    {
        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );

        new ExportAuditLogger($em, $context, new MockClock('2026-11-13 10:00:00'))
            ->log(new User(), 'own_hospital_allocations', [1], [], 3, false, true);

        self::assertInstanceOf(AuditEntry::class, $persisted);

        return $persisted;
    }
}
