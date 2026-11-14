<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\PlausibilityCellSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A cell of a plausibility snapshot (ADR 033), keyed by the canonical MTS diagram code and either.
 *
 * - an exact PZC (exact cell; with an MTS indicator: indicator cell, reserved and not read for the bonus), or
 * - the code of a problem area (fallback cell).
 *
 * Points are 0 … +3, never negative. Positives and negatives are the learning job's counts over all hospitals; they
 * decide whether an exact cell has enough observations and are never shown on a page (ADR 033, Privacy). Not audited:
 * written in bulk while the snapshot is a draft.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'encounter_plausibility_cell')]
#[ORM\Index(name: 'idx_encounter_plausibility_cell_snapshot', fields: ['snapshot'])]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_cell_exact', fields: ['snapshot', 'pzc', 'diagramCode'], options: ['where' => '((pzc IS NOT NULL) AND (indicator_code IS NULL))'])]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_cell_indicator', fields: ['snapshot', 'pzc', 'diagramCode', 'indicatorCode'], options: ['where' => '(indicator_code IS NOT NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_cell_area', fields: ['snapshot', 'areaCode', 'diagramCode'], options: ['where' => '(area_code IS NOT NULL)'])]
class PlausibilityCell
{
    public const int MIN_POINTS = 0;

    /** Cap of the bonus (ADR 033). */
    public const int MAX_POINTS = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlausibilitySnapshot $snapshot;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $pzc;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $areaCode;

    #[ORM\Column(length: 64)]
    private string $diagramCode;

    /** Reserved finer level (ADR 033); the matcher does not read indicator cells. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $indicatorCode;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $points;

    #[ORM\Column(length: 16, enumType: PlausibilityCellSource::class)]
    private PlausibilityCellSource $source;

    #[ORM\Column]
    private int $positives;

    #[ORM\Column]
    private int $negatives;

    public function __construct(
        PlausibilitySnapshot $snapshot,
        ?int $pzc,
        ?string $areaCode,
        string $diagramCode,
        int $points,
        PlausibilityCellSource $source,
        int $positives = 0,
        int $negatives = 0,
        ?string $indicatorCode = null,
    ) {
        if ((null === $pzc) === (null === $areaCode)) {
            throw new \InvalidArgumentException('A plausibility cell has either a PZC or a problem area.');
        }

        if (null !== $indicatorCode && null === $pzc) {
            throw new \InvalidArgumentException('An indicator cell needs an exact PZC.');
        }

        if ($positives < 0 || $negatives < 0) {
            throw new \InvalidArgumentException('Counts of a plausibility cell cannot be negative.');
        }

        self::assertPoints($points);
        $this->snapshot = $snapshot;
        $this->pzc = $pzc;
        $this->areaCode = $areaCode;
        $this->diagramCode = $diagramCode;
        $this->indicatorCode = $indicatorCode;
        $this->points = $points;
        $this->source = $source;
        $this->positives = $positives;
        $this->negatives = $negatives;
    }

    public static function assertPoints(int $points): void
    {
        if ($points < self::MIN_POINTS || $points > self::MAX_POINTS) {
            throw new \InvalidArgumentException(\sprintf('Plausibility points are %d … %d, got %d.', self::MIN_POINTS, self::MAX_POINTS, $points));
        }
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshot(): PlausibilitySnapshot
    {
        return $this->snapshot;
    }

    public function getPzc(): ?int
    {
        return $this->pzc;
    }

    public function getAreaCode(): ?string
    {
        return $this->areaCode;
    }

    public function getDiagramCode(): string
    {
        return $this->diagramCode;
    }

    public function getIndicatorCode(): ?string
    {
        return $this->indicatorCode;
    }

    public function getSource(): PlausibilityCellSource
    {
        return $this->source;
    }

    public function getPositives(): int
    {
        return $this->positives;
    }

    public function getNegatives(): int
    {
        return $this->negatives;
    }
}
