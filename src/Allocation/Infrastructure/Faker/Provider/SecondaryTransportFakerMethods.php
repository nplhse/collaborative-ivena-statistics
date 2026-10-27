<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * Lets PHPStan see the methods of the SecondaryTransport faker provider on a generator.
 */
interface SecondaryTransportFakerMethods
{
    /**
     * @return non-empty-string
     */
    public function secondaryTransport(): string;
}
