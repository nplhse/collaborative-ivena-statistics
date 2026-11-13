<?php

declare(strict_types=1);

namespace App\Encounter\Application\Dimension;

/**
 * Value type of a field of the canonical exchange format (ADR 026).
 */
enum EncounterFieldType: string
{
    /** Lowercase hex HMAC-SHA256 (64 characters). */
    case Hmac = 'hmac';
    case Integer = 'integer';
    case Decimal = 'decimal';
    /** `YYYY-MM-DDTHH:MM`, Europe/Berlin local time. */
    case Timestamp = 'timestamp';
    /** One value of a fixed code list. */
    case Code = 'code';
    /** Comma-separated values of a fixed code list. */
    case CodeSet = 'code_set';
    /** Code of an external catalog (MTS, department reference catalog); checked by the import. */
    case CatalogCode = 'catalog_code';
    /** `1` or empty (unknown). */
    case Flag = 'flag';
}
