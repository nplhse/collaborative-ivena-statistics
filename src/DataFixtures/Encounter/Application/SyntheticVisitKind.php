<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Application;

/**
 * Group of a generated ED visit; the truth that matcher tests compare against (#1052).
 */
enum SyntheticVisitKind: string
{
    /** The visit of an allocation: its true link. */
    case Linked = 'linked';

    /** EMS arrival without an IVENA allocation. */
    case EmsWithoutAllocation = 'ems_without_allocation';

    case SelfPresenter = 'self_presenter';

    /** Arrival mode `other` or not documented. */
    case UnknownRoute = 'unknown_route';

    /** A further visit under the case number of an earlier visit (self-presenter, hours later). */
    case RepeatVisit = 'repeat_visit';
}
