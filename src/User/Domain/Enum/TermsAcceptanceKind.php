<?php

declare(strict_types=1);

namespace App\User\Domain\Enum;

/** What a stored acceptance confirms (#1564, ADR 043). */
enum TermsAcceptanceKind: string
{
    /** The terms of use, at registration or at login after a new version. */
    case Terms = 'terms';
}
