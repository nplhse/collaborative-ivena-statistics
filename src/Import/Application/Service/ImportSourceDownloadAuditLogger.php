<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Domain\Entity\Import;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists an explicit audit row for successful import source-file downloads (SEC-008).
 *
 * Doctrine's AuditingDoctrineSubscriber only writes on audited entity mutations;
 * beginIntent alone does not create a durable entry.
 */
final readonly class ImportSourceDownloadAuditLogger
{
    public const string INTENT = 'import.source_file.downloaded';

    public const string ACTION = 'download';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
    ) {
    }

    public function log(User $actor, Import $import): void
    {
        $importId = $import->getId();
        if (null === $importId) {
            throw new \InvalidArgumentException('Cannot audit download for an Import without an ID.');
        }

        $requestId = $this->auditContext->ensureRequestId(static fn (): string => bin2hex(random_bytes(16)));
        $origin = $this->auditContext->getOrigin() ?? AuditContext::ORIGIN_HTTP;

        $metadata = [
            'intent' => self::INTENT,
            'import_id' => $importId,
        ];

        $clientIp = $this->auditContext->getClientIp();
        if (null !== $clientIp && '' !== $clientIp) {
            $metadata['clientIp'] = $clientIp;
        }

        $userAgent = $this->auditContext->getUserAgent();
        if (null !== $userAgent && '' !== $userAgent) {
            $metadata['userAgent'] = $userAgent;
        }

        $this->entityManager->persist(new AuditEntry(
            new \DateTimeImmutable('now'),
            $requestId,
            $actor,
            $origin,
            self::ACTION,
            Import::class,
            (string) $importId,
            [],
            $metadata,
        ));
        $this->entityManager->flush();
    }
}
