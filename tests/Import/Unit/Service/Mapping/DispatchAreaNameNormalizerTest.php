<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use App\Import\Infrastructure\Mapping\DispatchAreaNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DispatchAreaNameNormalizerTest extends TestCase
{
    private DispatchAreaNameNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new DispatchAreaNameNormalizer();
    }

    #[DataProvider('dispatchAreaProvider')]
    public function testNormalize(?string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function dispatchAreaProvider(): iterable
    {
        yield 'issue 126 Groá-Gerau typo' => ['Groá-Gerau', 'Groß-Gerau'];
        yield 'issue 122 trailing Kreis without dash' => ['Rheingau Taunus Kreis', 'Rheingau Taunus'];
        yield 'unchanged canonical name' => ['Rheingau Taunus', 'Rheingau Taunus'];
        yield 'leading Leitstelle prefix' => ['Leitstelle Nord', 'Nord'];
        yield 'leading Kreis prefix' => ['Kreis Offenbach', 'Offenbach'];
        yield 'parenthetical suffix' => ['Frankfurt (Main)', 'Frankfurt'];
        yield 'trailing dash Kreis suffix' => ['Main-Taunus - Kreis', 'Main-Taunus'];
        yield 'null' => [null, null];
        yield 'empty string' => ['', null];
        yield 'whitespace only' => ['   ', null];
        yield 'disponent plain' => ['Leitstelle Schwalm-Eder (Disponent)', 'Schwalm-Eder'];
        yield 'disponent5' => ['Leitstelle Kassel (Disponent5)', 'Kassel'];
        yield 'disponent-gi' => ['Leitstelle Gießen (Disponent-Gi)', 'Gießen'];
        yield 'zlst-disponent' => ['Leitstelle Lahn-Dill-Kreis (ZLST-Disponent)', 'Lahn-Dill'];
        yield 'disponent mtk variant' => ['Leitstelle Main-Taunus (Disponent - MTK)', 'Main-Taunus'];
        yield 'c4 schnittstelle' => ['Leitstelle Marburg-Biedenkopf (Schnittstelle Einsatzleitsystem ISE C4)', 'Marburg-Biedenkopf'];
        yield 'berlin disponent account' => ['Berlin (Disponent)', 'Berlin'];
        yield 'test area account' => ['Test Area (Disponent)', 'Test Area'];
    }
}
