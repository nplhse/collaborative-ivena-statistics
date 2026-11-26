<?php

declare(strict_types=1);

namespace App\User\Application\Terms;

/** The published terms page in one language (#1564): what a user is shown when they accept. */
final readonly class TermsDocument
{
    public function __construct(
        /** Language of the shown translation, which may be the fallback language. */
        public string $locale,
        /** SHA-256 of the published page content, as evidence of the text that was shown. */
        public string $contentHash,
    ) {
    }
}
