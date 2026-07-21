<?php

declare(strict_types=1);

namespace App\Allocation\Application\Audit;

use App\Allocation\Infrastructure\Query\ImportAssessmentAuditPurgeQuery;

final readonly class ImportAssessmentAuditPurgeService
{
    public function __construct(
        private ImportAssessmentAuditPurgeQuery $purgeQuery,
    ) {
    }

    public function countCandidates(): int
    {
        return $this->purgeQuery->countCandidates();
    }

    /**
     * @return array{min: \DateTimeImmutable, max: \DateTimeImmutable}|null
     */
    public function fetchOccurredAtRange(): ?array
    {
        return $this->purgeQuery->fetchOccurredAtRange();
    }

    public function deleteCandidates(): int
    {
        return $this->purgeQuery->deleteCandidates();
    }
}
