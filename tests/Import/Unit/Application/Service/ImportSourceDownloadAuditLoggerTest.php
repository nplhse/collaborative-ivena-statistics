<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Service\ImportSourceDownloadAuditLogger;
use App\Import\Domain\Entity\Import;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ImportSourceDownloadAuditLoggerTest extends TestCase
{
    public function testLogPersistsAuditEntryWithIntentMetadata(): void
    {
        $import = $this->createStub(Import::class);
        $import->method('getId')->willReturn(42);

        $actor = $this->createStub(User::class);

        $auditContext = new AuditContext();
        $auditContext->setRequestId('req-123');
        $auditContext->setOrigin(AuditContext::ORIGIN_HTTP);
        $auditContext->setClientIp('203.0.113.50');
        $auditContext->setUserAgent('PHPUnit');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (object $entry) use ($actor): bool {
                self::assertInstanceOf(AuditEntry::class, $entry);
                self::assertSame($actor, $entry->getActor());
                self::assertSame(ImportSourceDownloadAuditLogger::ACTION, $entry->getAction());
                self::assertSame(Import::class, $entry->getEntityClass());
                self::assertSame('42', $entry->getEntityId());
                self::assertSame(ImportSourceDownloadAuditLogger::INTENT, $entry->getMetadata()['intent'] ?? null);
                self::assertSame(42, $entry->getMetadata()['import_id'] ?? null);
                self::assertSame('203.0.113.50', $entry->getMetadata()['clientIp'] ?? null);
                self::assertSame('PHPUnit', $entry->getMetadata()['userAgent'] ?? null);

                return true;
            }));
        $entityManager->expects(self::once())->method('flush');

        $logger = new ImportSourceDownloadAuditLogger($entityManager, $auditContext);
        $logger->log($actor, $import);
    }

    public function testLogThrowsWhenImportHasNoId(): void
    {
        $import = $this->createStub(Import::class);
        $import->method('getId')->willReturn(null);

        $logger = new ImportSourceDownloadAuditLogger(
            $this->createStub(EntityManagerInterface::class),
            new AuditContext(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $logger->log($this->createStub(User::class), $import);
    }
}
