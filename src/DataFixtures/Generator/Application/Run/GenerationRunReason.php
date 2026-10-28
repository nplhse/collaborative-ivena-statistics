<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * Reason codes of failed hospitals and runs. Callers may pass their own codes for generation failures;
 * these are the ones the run tracking sets itself.
 */
final class GenerationRunReason
{
    /** Messenger gave up on the message of the asynchronous stage. */
    public const string RETRIES_EXHAUSTED = 'retries_exhausted';

    /** At least one hospital of the run failed. */
    public const string HOSPITAL_FAILED = 'hospital_failed';

    /** The synchronous stage ended without generating any hospital. */
    public const string NOTHING_GENERATED = 'nothing_generated';
}
