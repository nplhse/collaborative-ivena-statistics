<?php

declare(strict_types=1);

namespace App\Statistics\CaseFlow\Application;

final class CaseFlowPrivacyPolicy
{
    public const int MIN_HOSPITALS_PER_DESTINATION_POOL = 2;

    public const int MIN_CASES_PER_CELL = 10;

    public const int MIN_CASES_PER_ORIGIN_BAR = 10;

    public const int MAX_VISIBLE_ORIGINS = 8;

    public const int MIN_SYSTEM_INSIGHT_CASES = 100;

    public const int MIN_HOSPITAL_INSIGHT_CASES = 30;

    public const string SUPPRESSED_POOL_KEY = 'suppressed';

    public const string OTHER_ORIGIN_KEY = 'other';
}
