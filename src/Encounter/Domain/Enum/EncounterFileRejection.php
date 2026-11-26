<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Enum;

/**
 * Why an encounter import rejected its file as a whole (ADR 026, #1048). Stored on the import; never
 * the file content.
 */
enum EncounterFileRejection: string
{
    /** No encounter manifest, or source columns in the header: a raw HIS export. The file is deleted. */
    case RawExport = 'raw_export';
    /** Content checks found possible direct identifiers. The file is deleted. */
    case IdentifyingContent = 'identifying_content';
    case EncodingInvalid = 'encoding_invalid';
    case ManifestInvalid = 'manifest_invalid';
    case SchemaUnsupported = 'schema_unsupported';
    case ProfileUnsupported = 'profile_unsupported';
    case ToolVersionTooOld = 'tool_version_too_old';
    case FingerprintRuleUnsupported = 'fingerprint_rule_unsupported';
    case HeaderMismatch = 'header_mismatch';
    case RowCountMismatch = 'row_count_mismatch';
    /** The key check value belongs to an earlier key generation of the hospital (ADR 027). */
    case KeyCheckRetired = 'key_check_retired';
    /** The key check value differs from the active one of the hospital (ADR 027). */
    case KeyCheckMismatch = 'key_check_mismatch';

    /**
     * Files that may hold direct identifiers are deleted at once instead of being kept for error analysis.
     */
    public function deletesSourceFile(): bool
    {
        return self::RawExport === $this || self::IdentifyingContent === $this;
    }
}
