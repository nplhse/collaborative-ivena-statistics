<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Infrastructure\Matching;

use App\Allocation\Domain\Entity\Hospital;
use App\LegacyMigration\Infrastructure\Matching\HospitalMatcher;
use App\LegacyMigration\Infrastructure\Matching\HospitalNameNormalizer;
use PHPUnit\Framework\TestCase;

final class HospitalMatcherTest extends TestCase
{
    public function testFindsUniqueMatch(): void
    {
        $matcher = new HospitalMatcher(new HospitalNameNormalizer());
        $h1 = (new Hospital())->setName('Klinikum Musterstadt');
        $h2 = (new Hospital())->setName('St. Elisabeth');

        $result = $matcher->matchOrFail(5, 'Musterstadt', [$h1, $h2]);

        self::assertSame($h1, $result['hospital']);
        self::assertGreaterThan(0.85, $result['score']);
    }

    public function testFailsWhenNoMatchExists(): void
    {
        $matcher = new HospitalMatcher(new HospitalNameNormalizer());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hospital match');

        $matcher->matchOrFail(8, 'Completely Unknown', [(new Hospital())->setName('A')]);
    }

    public function testFailsOnAmbiguousMatches(): void
    {
        $matcher = new HospitalMatcher(new HospitalNameNormalizer());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous hospital match');

        $matcher->matchOrFail(11, 'St Marien', [
            (new Hospital())->setName('Klinik St. Marien'),
            (new Hospital())->setName('Klinikum St Marien'),
        ]);
    }
}

