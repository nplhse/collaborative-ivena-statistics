<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

use App\Shared\Infrastructure\Audit\AuditContext;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ExportAuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditContext $auditContext,
    ) {
    }

    /**
     * @param list<int>            $hospitalIds
     * @param array<string, mixed> $filters
     */
    public function log(
        User $user,
        string $exporterKey,
        array $hospitalIds,
        array $filters,
        int $rowCount,
        bool $blocked,
        bool $success,
    ): void {
        $requestId = $this->auditContext->ensureRequestId(static fn (): string => bin2hex(random_bytes(16)));
        $origin = $this->auditContext->getOrigin() ?? AuditContext::ORIGIN_HTTP;

        $this->entityManager->persist(new AuditEntry(
            new \DateTimeImmutable('now'),
            $requestId,
            $user,
            $origin,
            'export',
            'data_export',
            $exporterKey,
            [],
            [
                'exporterKey' => $exporterKey,
                'hospitalIds' => $hospitalIds,
                'filters' => $filters,
                'rowCount' => $rowCount,
                'blocked' => $blocked,
                'success' => $success,
            ],
        ));
        $this->entityManager->flush();
    }
}
