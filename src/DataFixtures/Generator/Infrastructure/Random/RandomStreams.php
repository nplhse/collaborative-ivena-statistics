<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Random;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Hands out one seeded random stream per seed, hospital, month and kind of data (ADR 022).
 *
 * A stream depends only on its key, so hospital X in month M gets the same values whether it is
 * generated alone, together with other hospitals or inside a longer period. The generator never
 * uses the global random state (mt_srand, mt_rand, random_int).
 */
final readonly class RandomStreams
{
    public const string VOLUME = 'volume';
    public const string ALLOCATIONS = 'allocations';
    public const string MCI = 'mci';

    public const string CLOSURES = 'closures';

    /** Upload time of the month's imports (`created_at`). */
    public const string UPLOAD = 'upload';

    /** Hospital profile; drawn once per hospital, so its month part is fixed. */
    public const string PROFILE = 'profile';

    /**
     * @param string $hospital public id of the hospital
     * @param string $month    month as Y-m
     */
    public function stream(int $seed, string $hospital, string $month, string $kind): Randomizer
    {
        return new Randomizer(new Mt19937(self::engineSeed(sprintf('%d|%s|%s|%s', $seed, $hospital, $month, $kind))));
    }

    /**
     * A 32-bit engine seed from the first four bytes of the key's SHA-256.
     */
    public static function engineSeed(string $key): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr(hash('sha256', $key, true), 0, 4));

        return $unpacked[1];
    }
}
