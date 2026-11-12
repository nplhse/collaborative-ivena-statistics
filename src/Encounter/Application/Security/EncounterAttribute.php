<?php

declare(strict_types=1);

namespace App\Encounter\Application\Security;

/**
 * Security attributes checked by EncounterVoter, always with the Hospital of the requested object as
 * subject (ADR 027). Routes for encounter objects resolve that hospital first.
 *
 * ROLE_ADMIN holds every grant action implicitly and passes every attribute.
 */
final class EncounterAttribute
{
    /** Encounter imports for the hospital. Grant action UPLOAD. */
    public const string UPLOAD = 'ENCOUNTER_UPLOAD';

    /** Match review decisions. Grant action REVIEW. */
    public const string REVIEW = 'ENCOUNTER_REVIEW';

    /** Case-level views: visit lists, the case card on an allocation, direct URLs. VIEW_CASES or REVIEW. */
    public const string VIEW_CASES = 'ENCOUNTER_VIEW_CASES';

    /** Descriptive aggregates of the hospital: any grant action plus the hospital STATISTICS permission. */
    public const string VIEW_AGGREGATES = 'ENCOUNTER_VIEW_AGGREGATES';

    /** Viewing a retained upload file. Grant action UPLOAD. */
    public const string VIEW_SOURCE_FILE = 'ENCOUNTER_VIEW_SOURCE_FILE';

    /** Downloading a retained upload file. Grant action UPLOAD. */
    public const string DOWNLOAD_SOURCE_FILE = 'ENCOUNTER_DOWNLOAD_SOURCE_FILE';

    /** Reprocessing a retained upload file without showing its content. Grant action UPLOAD. */
    public const string REPROCESS_SOURCE_FILE = 'ENCOUNTER_REPROCESS_SOURCE_FILE';

    /** Deleting an encounter import with its visits, links and review decisions. Grant action UPLOAD. */
    public const string DELETE_IMPORT = 'ENCOUNTER_DELETE_IMPORT';

    /** Changing the pseudonym key (ADR 026). Hospital owner or ROLE_ADMIN; no grant action allows it. */
    public const string CHANGE_KEY = 'ENCOUNTER_CHANGE_KEY';

    /** Managing the encounter grants of the hospital. Hospital owner or ROLE_ADMIN. */
    public const string MANAGE_GRANTS = 'ENCOUNTER_MANAGE_GRANTS';

    public const array ALL = [
        self::UPLOAD,
        self::REVIEW,
        self::VIEW_CASES,
        self::VIEW_AGGREGATES,
        self::VIEW_SOURCE_FILE,
        self::DOWNLOAD_SOURCE_FILE,
        self::REPROCESS_SOURCE_FILE,
        self::DELETE_IMPORT,
        self::CHANGE_KEY,
        self::MANAGE_GRANTS,
    ];
}
