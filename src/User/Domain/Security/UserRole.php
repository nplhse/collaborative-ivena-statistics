<?php

declare(strict_types=1);

namespace App\User\Domain\Security;

final class UserRole
{
    public const string USER = 'ROLE_USER';

    public const string PARTICIPANT = 'ROLE_PARTICIPANT';

    public const string BOARD_MEMBER = 'ROLE_BOARD_MEMBER';

    public const string ADMIN = 'ROLE_ADMIN';

    public const string REVIEW_INDICATIONS = 'ROLE_REVIEW_INDICATIONS';

    /**
     * Opt-in beta switch for closure imports and analytics. Not inherited by ROLE_ADMIN.
     */
    public const string CLOSURE_BETA = 'ROLE_CLOSURE_BETA';

    /**
     * Participation in the encounter data pilot (ADR 028): pilot pages and navigation only, no case access.
     * Inherited by ROLE_ADMIN.
     */
    public const string ENCOUNTER_BETA = 'ROLE_ENCOUNTER_BETA';

    /**
     * Review of the matcher's plausibility table (ADR 033): edit the curated problem areas, read snapshot reviews,
     * activate and retire snapshots. Works only together with ROLE_ENCOUNTER_BETA; grants no case access. Inherited by
     * ROLE_ADMIN.
     */
    public const string ENCOUNTER_CATALOG_REVIEWER = 'ROLE_ENCOUNTER_CATALOG_REVIEWER';

    public static function containsParticipant(mixed $roles): bool
    {
        if (!\is_array($roles)) {
            return false;
        }

        return array_any($roles, fn ($role): bool => self::PARTICIPANT === $role);
    }
}
