<?php

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\Domain\Enum\TimeGridMode;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent(name: 'TimeGridChart', template: '@Statistics/components/TimeGridChart.html.twig')]
final class TimeGridChart
{
    /**
     * @var list<array{label:string, isTotal?:bool}>
     */
    public array $columns = [];

    /**
     * @var list<array{
     *  label:string,
     *  format:string,
     *  cells:list<\App\Statistics\Domain\Model\TimeGridCell|array{value?:int|float, compare?:int|float}>
     * }>
     */
    public array $rows = [];

    public TimeGridMode $mode = TimeGridMode::RAW;
    public string $metrics = 'default';
    public string $view = 'int';
    public int $height = 240;
    public ?string $domId = null;

    /**
     * @var list<string>
     */
    public array $labels = [];

    /**
     * @var list<array{name:string, data:list<float|null>}>
     */
    public array $series = [];

    /**
     * @var list<int>
     */
    public array $dashArray = [];

    #[PostMount]
    public function init(): void
    {
        $this->labels = array_column(
            array_filter(
                $this->columns,
                /** @param array{label:string,isTotal?:bool} $c */
                fn (array $c): bool => empty($c['isTotal'] ?? false)
            ),
            'label'
        );

        $primarySeries = [];
        $baselineSeries = [];

        foreach ($this->rows as $row) {
            $isTotalRow = 'total' === strtolower($row['label']);
            $isWantedFormat = $row['format'] === $this->view;

            if ('default' === $this->metrics || 'all' === $this->metrics) {
                if (!$isTotalRow) {
                    continue;
                }
            } else {
                if ($isTotalRow || !$isWantedFormat) {
                    continue;
                }
            }

            $primaryData = [];
            foreach ($this->labels as $i => $_) {
                $cell = $row['cells'][$i] ?? null;
                $v = ($cell instanceof \App\Statistics\Domain\Model\TimeGridCell)
                    ? $cell->value
                    : (is_array($cell) ? ($cell['value'] ?? null) : null);
                $primaryData[] = is_numeric($v) ? (float) $v : null;
            }
            $primarySeries[] = ['name' => $row['label'], 'data' => $primaryData];

            if (TimeGridMode::COMPARE === $this->mode) {
                $baselineData = [];
                foreach ($this->labels as $i => $_) {
                    $cell = $row['cells'][$i] ?? null;
                    $c = ($cell instanceof \App\Statistics\Domain\Model\TimeGridCell)
                        ? $cell->compare
                        : (is_array($cell) ? ($cell['compare'] ?? null) : null);
                    $baselineData[] = is_numeric($c) ? (float) $c : null;
                }
                $baselineSeries[] = [
                    'name' => $row['label'].' (baseline)',
                    'data' => $baselineData,
                ];
            }
        }

        $this->series = array_merge($primarySeries, $baselineSeries);
        $this->dashArray = array_merge(
            array_fill(0, count($primarySeries), 0),
            array_fill(0, count($baselineSeries), 6)
        );

        if (null === $this->domId) {
            $sig = $this->metrics.'|'.$this->view.'|'.$this->mode->value.'|'.implode('|', $this->labels);
            $this->domId = 'chart-timegrid-'.substr(sha1($sig), 0, 8);
        }
    }
}
