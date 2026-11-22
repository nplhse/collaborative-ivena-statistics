<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Unit\UI\Twig;

use Cois\Plugin\EdVisit\Application\Matching\MatchingSettings;
use Cois\Plugin\EdVisit\Application\Validation\ScoreBands;
use Cois\Plugin\EdVisit\UI\Twig\AgreementBadgeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgreementBadgeExtensionTest extends TestCase
{
    /**
     * Default configuration: proposal 0.50, auto-accept 0.90, bands 0.50, 0.70, 0.90, 0.99.
     *
     * @return iterable<string, array{float, string}>
     */
    public static function defaultBoundaries(): iterable
    {
        yield 'lowest score' => [0.0, 'bg-red-lt'];
        yield 'just below the proposal threshold' => [0.4999, 'bg-red-lt'];
        yield 'at the proposal threshold' => [0.50, 'bg-orange-lt'];
        yield 'just below the 0.70 band' => [0.6999, 'bg-orange-lt'];
        yield 'at the 0.70 band' => [0.70, 'bg-yellow-lt'];
        yield 'just below auto-accept' => [0.8999, 'bg-yellow-lt'];
        yield 'at auto-accept' => [0.90, 'bg-green-lt'];
        yield 'in the top band' => [0.99, 'bg-green-lt'];
        yield 'full agreement' => [1.0, 'bg-green-lt'];
    }

    #[DataProvider('defaultBoundaries')]
    public function testColourFollowsTheDefaultThresholdsAndBands(float $score, string $expected): void
    {
        self::assertSame($expected, new AgreementBadgeExtension(new MatchingSettings(), new ScoreBands())->badgeClass($score));
    }

    public function testColourMovesWithChangedThresholds(): void
    {
        $extension = new AgreementBadgeExtension(new MatchingSettings(0.80, 0.60), new ScoreBands([0.60, 0.70, 0.95]));

        self::assertSame('bg-red-lt', $extension->badgeClass(0.59));
        self::assertSame('bg-orange-lt', $extension->badgeClass(0.65));
        self::assertSame('bg-yellow-lt', $extension->badgeClass(0.75));
        self::assertSame('bg-green-lt', $extension->badgeClass(0.85));
        self::assertSame('bg-green-lt', $extension->badgeClass(0.95));
    }

    public function testWithoutABandBetweenTheThresholdsEveryProposalIsYellow(): void
    {
        $extension = new AgreementBadgeExtension(new MatchingSettings(), new ScoreBands([0.50, 0.90]));

        self::assertSame('bg-yellow-lt', $extension->badgeClass(0.55));
        self::assertSame('bg-green-lt', $extension->badgeClass(0.95));
    }
}
