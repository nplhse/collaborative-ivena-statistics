<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

/**
 * Identity of the reference catalog package format (ADR 020).
 *
 * Exports always use the current version. Version 1 has no districts and can still be read (ADR 041).
 */
final class ReferenceCatalogFormat
{
    public const string NAME = 'ivena-reference-catalog';

    public const int VERSION = 2;

    public const int VERSION_WITHOUT_DISTRICTS = 1;

    public const array SUPPORTED_VERSIONS = [self::VERSION_WITHOUT_DISTRICTS, self::VERSION];

    public const string CHECKSUM_ALGORITHM = 'sha256';
}
