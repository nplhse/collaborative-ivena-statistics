<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

/**
 * Identity of the reference catalog package format (ADR 020).
 *
 * Only the current version is accepted; a format change raises it.
 */
final class ReferenceCatalogFormat
{
    public const string NAME = 'ivena-reference-catalog';

    public const int VERSION = 1;

    public const string CHECKSUM_ALGORITHM = 'sha256';
}
