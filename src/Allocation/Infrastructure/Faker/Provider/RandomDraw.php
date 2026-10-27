<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Draws for the synthetic reference catalog from a passed Randomizer instead of the global mt_rand() state.
 *
 * The catalog builder gives every entry its own seeded stream (ADR 022), so other code in the
 * same process cannot shift the catalog. Faker-based callers such as factories get a stream
 * seeded from Faker's state and stay reproducible with Foundry's seed.
 */
final class RandomDraw
{
    /**
     * @template T
     *
     * @param list<T> $list
     *
     * @return T
     */
    public static function element(array $list, Randomizer $random): mixed
    {
        if ([] === $list) {
            throw new \InvalidArgumentException('Cannot draw from an empty list.');
        }

        return $list[$random->getInt(0, \count($list) - 1)];
    }

    public static function fromFaker(): Randomizer
    {
        return new Randomizer(new Mt19937(Base::numberBetween(0, mt_getrandmax())));
    }
}
