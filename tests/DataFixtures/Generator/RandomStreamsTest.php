<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\DataFixtures\Generator\Infrastructure\Random\RandomStreams;
use PHPUnit\Framework\TestCase;
use Random\Randomizer;

final class RandomStreamsTest extends TestCase
{
    private const string HOSPITAL = '0c8f1a4e-5d1b-5a5e-9a52-2f6f6c1b9a10';

    public function testTheSameKeyGivesTheSameValues(): void
    {
        $streams = new RandomStreams();

        self::assertSame(
            $this->values($streams->stream(740, self::HOSPITAL, '2026-09', RandomStreams::ALLOCATIONS)),
            $this->values($streams->stream(740, self::HOSPITAL, '2026-09', RandomStreams::ALLOCATIONS)),
        );
    }

    public function testEveryPartOfTheKeyChangesTheStream(): void
    {
        $streams = new RandomStreams();
        $base = $this->values($streams->stream(740, self::HOSPITAL, '2026-09', RandomStreams::ALLOCATIONS));

        self::assertNotSame($base, $this->values($streams->stream(741, self::HOSPITAL, '2026-09', RandomStreams::ALLOCATIONS)), 'seed');
        self::assertNotSame($base, $this->values($streams->stream(740, '9b2d7c61-0f3e-5c4a-8d1e-7a6b5c4d3e2f', '2026-09', RandomStreams::ALLOCATIONS)), 'hospital');
        self::assertNotSame($base, $this->values($streams->stream(740, self::HOSPITAL, '2026-10', RandomStreams::ALLOCATIONS)), 'month');
        self::assertNotSame($base, $this->values($streams->stream(740, self::HOSPITAL, '2026-09', RandomStreams::VOLUME)), 'kind');
    }

    public function testTheEngineSeedIsStable(): void
    {
        // A changed derivation changes every generated dataset (ADR 022); this pins it.
        self::assertSame(3_398_926_610, RandomStreams::engineSeed('a'));
    }

    /**
     * @return list<int>
     */
    private function values(Randomizer $random): array
    {
        return array_map(static fn (): int => $random->getInt(0, 1_000_000), range(1, 20));
    }
}
