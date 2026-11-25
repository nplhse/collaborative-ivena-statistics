<?php

declare(strict_types=1);

namespace App\Research\Domain\ValueObject;

/**
 * Everything the applicant enters for one version of a data extract request (ADR 045). Three groups: what all
 * participants see, the export scope that becomes the Board's decision, and internal details for the applicant, the
 * Board and administrators.
 */
final readonly class DataRequestContent
{
    public function __construct(
        // Visible to participants
        public string $title,
        public string $summary,
        public string $institution,
        public bool $enrichmentPlanned,
        // Export scope; visible to participants except the recipients
        public string $purpose,
        public \DateTimeImmutable $periodFrom,
        public \DateTimeImmutable $periodTo,
        public string $population,
        public string $variables,
        public string $granularity,
        public string $recipients,
        public ?string $enrichmentDetails,
        public bool $singleCases,
        // Internal
        public string $description,
        public string $dataNeeds,
        public string $responsiblePersons,
        public string $conflictsOfInterest,
        public string $ethics,
        public ?\DateTimeImmutable $plannedEnd = null,
        public ?\DateTimeImmutable $plannedDeletion = null,
        public ?string $plannedPublication = null,
    ) {
    }
}
