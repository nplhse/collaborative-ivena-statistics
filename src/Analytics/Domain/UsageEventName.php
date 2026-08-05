<?php

declare(strict_types=1);

namespace App\Analytics\Domain;

/**
 * Canonical usage event names. No DSL — plain string constants.
 */
final class UsageEventName
{
    public const string ANALYSIS_LIBRARY_OPENED = 'analysis.library.opened';

    public const string ANALYSIS_EXPLORER_OPENED = 'analysis.explorer.opened';

    public const string ANALYSIS_SAVED_VIEW_OPENED = 'analysis.saved_view.opened';

    public const string ANALYSIS_EXPLORER_RUN = 'analysis.explorer.run';

    public const string ANALYSIS_SAVED_VIEW_CREATED = 'analysis.saved_view.created';

    public const string ANALYSIS_EXPLORER_EXPORTED_CSV = 'analysis.explorer.exported_csv';

    public const string IMPORT_STARTED = 'import.started';

    public const string IMPORT_COMPLETED = 'import.completed';

    public const string EXPLORE_ALLOCATION_OPENED = 'explore.allocation.opened';

    public const string EXPLORE_HOSPITAL_OPENED = 'explore.hospital.opened';

    public const string EXPLORE_INDICATION_OPENED = 'explore.indication.opened';

    public const string BENCHMARKING_OPENED = 'benchmarking.opened';

    public const string USER_REGISTERED = 'user.registered';

    public const string USER_EMAIL_CONFIRMED = 'user.email_confirmed';

    public const string USER_BECAME_PARTICIPANT = 'user.became_participant';

    public const string ONBOARDING_STEP_COMPLETED = 'onboarding.step.completed';

    private function __construct()
    {
    }
}
