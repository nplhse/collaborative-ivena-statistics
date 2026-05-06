<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Infrastructure\Matching;

use App\LegacyMigration\Infrastructure\Matching\HospitalNameNormalizer;
use PHPUnit\Framework\TestCase;

final class HospitalNameNormalizerTest extends TestCase
{
    public function testNormalizesUmlautsCasePunctuationAndSuffixes(): void
    {
        $normalizer = new HospitalNameNormalizer();

        self::assertSame(
            'muenster',
            $normalizer->normalize(' Universitätsklinikum Münster gGmbH ')
        );
        self::assertSame(
            'st marien',
            $normalizer->normalize('Klinik St. Marien GmbH')
        );
        self::assertSame(
            'charite',
            $normalizer->normalize('  Klinikum   Charité  ')
        );
    }
}
