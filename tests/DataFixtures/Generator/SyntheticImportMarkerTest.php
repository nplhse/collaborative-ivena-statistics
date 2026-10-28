<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\DataFixtures\Generator\Application\PatternSource;
use App\DataFixtures\Generator\Application\SyntheticImportMarker;
use PHPUnit\Framework\TestCase;

final class SyntheticImportMarkerTest extends TestCase
{
    private const string HOSPITAL = '058d70f7-a582-5338-aeec-23494cb7ca30';

    public function testThePathRecordsSeedSourceHospitalAndMonth(): void
    {
        $marker = SyntheticImportMarker::allocations(740, self::HOSPITAL, '2026-09');

        self::assertSame('synthetic/allocations/seed-740/defaults/'.self::HOSPITAL.'/2026-09.csv', $marker->path());
        self::assertEquals($marker, SyntheticImportMarker::fromPath($marker->path()));
    }

    public function testThePathRecordsAPackageChecksumAndAStructureSource(): void
    {
        $patterns = str_repeat('ab', 32);
        $structures = str_repeat('cd', 32);

        $package = SyntheticImportMarker::allocations(740, self::HOSPITAL, '2026-09', new PatternSource($patterns));
        self::assertSame('synthetic/allocations/seed-740/'.$patterns.'/'.self::HOSPITAL.'/2026-09.csv', $package->path());
        self::assertEquals($package, SyntheticImportMarker::fromPath($package->path()));

        // #876: the structure part follows a "+" and only when it is not the defaults.
        $both = SyntheticImportMarker::closures(740, self::HOSPITAL, '2026-09', new PatternSource($patterns, $structures));
        self::assertSame('synthetic/closures/seed-740/'.$patterns.'+'.$structures.'/'.self::HOSPITAL.'/2026-09.csv', $both->path());
        self::assertEquals($both, SyntheticImportMarker::fromPath($both->path()));

        $structuresOnly = new PatternSource(PatternSource::DEFAULTS, $structures);
        self::assertSame('defaults+'.$structures, $structuresOnly->slot());
        self::assertEquals($structuresOnly, PatternSource::fromSlot($structuresOnly->slot()));
        self::assertSame('defaults', PatternSource::fromSlot('defaults+defaults')->slot(), 'a defaults structure part is never written');
    }

    public function testAnInvalidSourceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PatternSource('My Package');
    }

    public function testOtherPathsAreNoMarker(): void
    {
        self::assertNull(SyntheticImportMarker::fromPath('fixtures/synthetic.csv'));
        self::assertNull(SyntheticImportMarker::fromPath('imports/2026/09/upload.csv'));
        self::assertNull(SyntheticImportMarker::fromPath('synthetic/allocations/seed-x/defaults/'.self::HOSPITAL.'/2026-09.csv'));
        self::assertNull(SyntheticImportMarker::fromPath('synthetic/allocations/seed-1/a+b+c/'.self::HOSPITAL.'/2026-09.csv'));
    }

    public function testTheLikePatternMatchesEverySeed(): void
    {
        $like = SyntheticImportMarker::likeAnySeed(SyntheticImportMarker::ALLOCATIONS, self::HOSPITAL, '2026-09');
        $regex = '#^'.str_replace('%', '.*', preg_quote($like, '#')).'$#';

        self::assertMatchesRegularExpression($regex, SyntheticImportMarker::allocations(1, self::HOSPITAL, '2026-09')->path());
        self::assertMatchesRegularExpression($regex, SyntheticImportMarker::allocations(1, self::HOSPITAL, '2026-09', new PatternSource(str_repeat('ab', 32), 'x'))->path(), 'and every source');
        self::assertDoesNotMatchRegularExpression($regex, SyntheticImportMarker::allocations(1, self::HOSPITAL, '2026-10')->path());
    }
}
