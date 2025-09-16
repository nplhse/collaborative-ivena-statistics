<?php

namespace App\Faker\Provider;

/**
 * Added this interface to fix an issue with PHPStan that could not recognize
 * the indication() methods from the Indication faker provider. To make things
 * work we assign self::faker() just for this one case to a $faker variable and
 * manually assign this interface alongside the usual generator.
 */
interface IndicationFakerMethods
{
    /**
     * @return non-empty-string
     */
    public function indication(): string;

    /**
     * @return non-empty-string
     */
    public function indicationCode(): string;

    /**
     * @return non-empty-string
     */
    public function indicationName(): string;
}
