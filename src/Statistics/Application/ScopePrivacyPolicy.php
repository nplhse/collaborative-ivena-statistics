<?php

declare(strict_types=1);

namespace App\Statistics\Application;

/**
 * Minimum group sizes that protect the figures of single hospitals.
 */
final class ScopePrivacyPolicy
{
    /**
     * Minimum number of distinct hospitals behind a state, dispatch area or hospital cohort scope and
     * behind a Case Flow destination pool. With two hospitals, a participant who knows the figures of
     * their own hospital could subtract them from the group total and get those of the other one (#934).
     */
    public const int MIN_HOSPITALS = 3;
}
