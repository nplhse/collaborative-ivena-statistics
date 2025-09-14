<?php

namespace App\Faker\Provider;

/**
 * Added this interface to fix an issue with PHPStan that could not recognize
 * the occasion() method from the Occasion faker provider. To make things work
 * we assign self::faker() just for this one case to a $faker variable and
 * manually assign this interface alongside the usual generator.
 */
interface OccasionFakerMethods
{
    /**
     * Returns a random assignment name.
     *
     * @return non-empty-string
     */
    public function occasion(): string;
}
