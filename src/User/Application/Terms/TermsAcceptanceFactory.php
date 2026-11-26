<?php

declare(strict_types=1);

namespace App\User\Application\Terms;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Application\Contract\TermsDocumentProviderInterface;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;

/** Builds the acceptance of the terms as configured and published now (#1564, ADR 043); the caller persists it. */
final readonly class TermsAcceptanceFactory
{
    /** @psalm-suppress PossiblyUnusedMethod Built by the container. */
    public function __construct(
        private TermsVersion $termsVersion,
        private TermsDocumentProviderInterface $termsDocuments,
    ) {
    }

    public function forCurrentTerms(User $user, string $locale): TermsAcceptance
    {
        $document = $this->termsDocuments->current($locale);

        return new TermsAcceptance(
            $user,
            TermsAcceptanceKind::Terms,
            $this->termsVersion->current(),
            $document->locale ?? $locale,
            $document?->contentHash,
        );
    }

    /**
     * The authority to upload data for this hospital (#1565), under the terms version in force, or under the
     * version and time of an earlier confirmation (an assignment request approved later).
     */
    public function forUploadAuthority(User $user, Hospital $hospital, string $locale, ?\DateTimeImmutable $confirmedAt = null, ?string $termsVersion = null): TermsAcceptance
    {
        $earlier = $confirmedAt instanceof \DateTimeImmutable;
        $document = $earlier ? null : $this->termsDocuments->current($locale);

        return new TermsAcceptance(
            $user,
            TermsAcceptanceKind::UploadAuthority,
            $earlier ? $termsVersion : $this->termsVersion->current(),
            $document->locale ?? $locale,
            $document?->contentHash,
            $confirmedAt,
            $hospital,
        );
    }
}
