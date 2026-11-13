<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Application\Validation;

use App\Encounter\Application\Validation\ScoreBands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoreBandsTest extends TestCase
{
    /**
     * @return iterable<string, array{float, string}>
     */
    public static function scores(): iterable
    {
        yield 'below the first bound' => [0.30, '0.00–0.50'];
        yield 'first bound belongs to its band' => [0.50, '0.50–0.70'];
        yield 'just below a bound' => [0.8999, '0.70–0.90'];
        yield 'automatic threshold' => [0.90, '0.90–0.99'];
        yield 'sole candidate with 9 points' => [0.9697, '0.90–0.99'];
        yield 'last bound' => [0.99, '0.99–1.00'];
        yield 'one' => [1.0, '0.99–1.00'];
    }

    #[DataProvider('scores')]
    public function testBandOf(float $score, string $band): void
    {
        self::assertSame($band, new ScoreBands()->bandOf($score));
    }

    public function testBandsFromTheHighestDown(): void
    {
        self::assertSame(['0.99–1.00', '0.90–0.99', '0.70–0.90', '0.50–0.70'], array_column(new ScoreBands()->bands(), 'label'));
    }

    public function testRefusesUnorderedBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScoreBands([0.9, 0.5]);
    }
}
