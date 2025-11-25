<?php

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * Added this interface to fix an issue with PHPStan that could not recognize
 * the assignment() method from the Assignment faker provider. To make things
 * work we assign self::faker() just for this one case to a $faker variable and
 * manually assign this interface alongside the usual generator.
 */
interface AssignmentFakerMethods
{
    /**
     * Returns a random assignment name.
     *
     * @return non-empty-string
     */
    public function assignment(): string;
}
