<?php

declare(strict_types=1);

namespace App\User\Application\Terms;

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
}
