<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

/**
 * What the wizard could do for the maps of the hospital. Missing geodata never stops the wizard: the dataset does
 * not depend on it, the maps just lack the pin or the isochrones.
 */
final readonly class DemoLocationOutcome
{
    public const string COMPLETE = 'complete';

    /** Coordinates without isochrones: the pin shows, catchment areas do not. */
    public const string PARTIAL = 'partial';

    public const string NONE = 'none';

    /**
     * @param self::COMPLETE|self::PARTIAL|self::NONE $state
     */
    public function __construct(
        public string $state,
        public string $detail,
    ) {
    }

    public function isComplete(): bool
    {
        return self::COMPLETE === $this->state;
    }
}
