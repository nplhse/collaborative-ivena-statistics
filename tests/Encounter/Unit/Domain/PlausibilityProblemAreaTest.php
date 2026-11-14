<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Domain;

use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\PlausibilityAreaCode;
use App\Encounter\Domain\Entity\PlausibilityAreaDiagram;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use PHPUnit\Framework\TestCase;

/**
 * A curated problem area (ADR 033) keeps the codes and rules that stay when it is edited, so editing never deletes and
 * re-inserts a row (the unique PZC constraint would see both); PZC codes have three digits, rules 0 … +3 points.
 */
final class PlausibilityProblemAreaTest extends TestCase
{
    public function testReplacingCodesKeepsTheRowsThatStay(): void
    {
        $area = new PlausibilityProblemArea('urologie', ' Urologie ', ' ');
        self::assertSame('Urologie', $area->getGermanName(), 'names are trimmed');
        self::assertNull($area->getEnglishName(), 'an empty English name is none');

        $area->replaceCodes([713, 711, 712, 711]);
        self::assertSame([711, 712, 713], $area->pzcCodes());
        $kept = $this->codeRow($area, 712);

        $area->replaceCodes([712, 714]);
        self::assertSame([712, 714], $area->pzcCodes());
        self::assertSame($kept, $this->codeRow($area, 712), 'the row of 712 stays');
    }

    public function testReplacingDiagramsUpdatesPointsAndRemovesRules(): void
    {
        $area = new PlausibilityProblemArea('urologie', 'Urologie');
        $urology = $this->diagram('urologisches_problem');
        $back = $this->diagram('rueckenschmerz');
        $area->replaceDiagrams([[$urology, 3], [$back, 2]]);
        self::assertSame(['rueckenschmerz' => 2, 'urologisches_problem' => 3], $area->diagramPoints(), 'ordered by diagram code');
        $rule = $area->diagrams()[1];

        $area->replaceDiagrams([[$urology, 1]]);
        self::assertSame(['urologisches_problem' => 1], $area->diagramPoints());
        self::assertSame($rule, $area->diagrams()[0], 'the rule of urologisches_problem is updated, not replaced');
    }

    public function testCodesHaveThreeDigitsAndRulesAtMostThreePoints(): void
    {
        $area = new PlausibilityProblemArea('urologie', 'Urologie');
        foreach ([-1, 1000] as $pzc) {
            try {
                new PlausibilityAreaCode($area, $pzc);
                self::fail(\sprintf('PZC %d accepted', $pzc));
            } catch (\InvalidArgumentException) {
            }
        }

        $rule = new PlausibilityAreaDiagram($area, $this->diagram('urologisches_problem'), 0);
        self::assertSame(0, $rule->getPoints());
        $this->expectException(\InvalidArgumentException::class);
        $rule->setPoints(4);
    }

    private function codeRow(PlausibilityProblemArea $area, int $pzc): PlausibilityAreaCode
    {
        $codes = new \ReflectionProperty(PlausibilityProblemArea::class, 'codes')->getValue($area);
        foreach ($codes as $code) {
            if ($code->getPzc() === $pzc) {
                return $code;
            }
        }

        self::fail('no row for '.$pzc);
    }

    private function diagram(string $code): MtsDiagram
    {
        return new MtsDiagram($code, $code, MtsCatalogSource::OrbisExport, MtsCatalogStatus::Curated);
    }
}
