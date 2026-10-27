<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * Added this interface to fix an issue with PHPStan that could not recognize
 * the hospital() method from the Hospital faker provider. To make things work
 * we assign self::faker() just for this one case to a $faker variable and
 * manually assign this interface alongside the usual generator.
 */
interface HospitalFakerMethods
{
    /**
     * Returns a random hospital name.
     *
     * @return non-empty-string
     */
    public function hospital(): string;

    /**
     * A complete synthetic hospital in a dispatch area of Hesse.
     *
     * @return array{
     *     name: string,
     *     tier: 'Basic'|'Extended'|'Full',
     *     size: 'Small'|'Medium'|'Large',
     *     beds: int,
     *     location: 'Urban'|'Mixed'|'Rural',
     *     address: array{street: string, postal_code: string, city: string, state: string, country: string},
     *     coordinates: array{lat: float, lng: float}
     * }
     */
    public function hospitalProfile(string $dispatchArea): array;
}
