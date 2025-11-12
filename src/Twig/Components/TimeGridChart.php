<?php

namespace App\Twig\Components;

use App\Enum\TimeGridMode;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent('TimeGridChart')]
final class TimeGridChart
{
    public array $columns = [];
    public array $rows = [];
    public TimeGridMode $mode;
    public string $metrics = 'default';
    public string $view = 'int';
    public int $height = 240;
    public ?string $domId = null;

    public array $labels = [];
    public array $series = [];
    public array $dashArray = [];

    #[PostMount]
    public function init(): void
    {
        $this->labels = array_values(array_column(
            array_filter($this->columns, fn($c) => empty($c['isTotal'] ?? false)),
            'label'
        ));

        $primarySeries  = [];
        $baselineSeries = [];

        foreach ($this->rows as $row) {
            $isTotalRow     = strtolower($row['label']) === 'total';
            $isWantedFormat = $row['format'] === $this->view;

            if ($this->metrics === 'default' || $this->metrics === 'all') {
                if (!$isTotalRow) continue;
            } else {
                if ($isTotalRow || !$isWantedFormat) continue;
            }

            $primaryData = [];
            foreach ($this->labels as $i => $_) {
                $cell = $row['cells'][$i] ?? null;
                $primaryData[] = (isset($cell->value) && is_numeric($cell->value)) ? (float) $cell->value : null;
            }
            $primarySeries[] = ['name' => $row['label'], 'data' => $primaryData];

            if ($this->mode === TimeGridMode::COMPARE) {
                $baselineData = [];
                foreach ($this->labels as $i => $_) {
                    $cell = $row['cells'][$i] ?? null;
                    $baselineData[] = (isset($cell->compare) && is_numeric($cell->compare)) ? (float) $cell->compare : null;
                }
                $baselineSeries[] = [
                    'name' => $row['label'] . ' (baseline)',
                    'data' => $baselineData,
                ];
            }
        }

        $this->series    = array_merge($primarySeries, $baselineSeries);
        $this->dashArray = array_merge(
            array_fill(0, count($primarySeries), 0),
            array_fill(0, count($baselineSeries), 6)
        );

        if ($this->domId === null) {
            $sig = $this->metrics.'|'.$this->view.'|'.$this->mode->value.'|'.implode('|', $this->labels);
            $this->domId = 'chart-timegrid-'.substr(sha1($sig), 0, 8);
        }
    }
}
