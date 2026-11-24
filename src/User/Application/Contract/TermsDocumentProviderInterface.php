<?php

declare(strict_types=1);

namespace App\User\Application\Contract;

use App\User\Application\Terms\TermsDocument;

/**
 * The terms of use page as it is published now (#1564, ADR 043). Implemented by Content, which owns the page.
 */
interface TermsDocumentProviderInterface
{
    /**
     * The published terms page for this language, with the same language fallback as the page itself; null when no
     * terms page is published.
     */
    public function current(string $locale): ?TermsDocument;
}
