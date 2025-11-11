<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\TimeGridMode;
use App\Model\TimeGridCell;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('TimeGridChart')]
final class TimeGridChart
{
    /** @var list<array{label:string,periodKey:string,isTotal?:bool}> */
    public array $columns = [];

    /** @var list<array{label:string,format:'int'|'pct',cells:list<TimeGridCell>}> */
    public array $rows = [];

    public TimeGridMode $mode = TimeGridMode::RAW;

    /** Output for the template */
    /** @var list<string> */
    public array $labels = [];

    /** @var list<array{name:string,data:list<float|null>}> */
    public array $series = [];

    public int $height = 240;
    public string $domId = '';

    /**
     * @param list<array{label:string,periodKey:string,isTotal?:bool}> $columns
     * @param list<array{label:string,format:'int'|'pct',cells:list<TimeGridCell>}> $rows
     */
    public function mount(array $columns, array $rows, TimeGridMode $mode = TimeGridMode::RAW, int $height = 240, ?string $domId = null): void
    {
        $this->columns = $columns;
        $this->rows    = $rows;
        $this->mode    = $mode;
        $this->height  = $height;
        $this->domId   = $domId ?? ('chart-timegrid-'.bin2hex(random_bytes(4)));

        // 1) labels (exclude final "Total" column)
        $this->labels = [];
        foreach ($this->columns as $c) {
            if (($c['isTotal'] ?? false) !== true) {
                $this->labels[] = $c['label'];
            }
        }

        // 2) series according to mode, mirroring the table
        $this->series = $this->buildSeries();
        // Optional: drop completely empty series
        $this->series = array_values(array_filter(
            $this->series,
            static fn(array $s) => isset($s['data']) && array_sum(array_map(static fn($v) => (float)($v ?? 0), $s['data'])) !== 0.0
        ));
    }

    /** @return list<array{name:string,data:list<float|null>}> */
    private function buildSeries(): array
    {
        $series = [];

        foreach ($this->rows as $row) {
            $name = $row['label'];

            if ($this->mode === TimeGridMode::RAW) {
                // Primary values
                $data = [];
                foreach ($row['cells'] as $idx => $cell) {
                    // skip last "Total" cell
                    if ($idx >= count($this->columns) - 1) { break; }
                    $data[] = is_numeric($cell->value) ? (float)$cell->value : null;
                }
                $series[] = ['name' => $name, 'data' => $data];
            }
            elseif ($this->mode === TimeGridMode::DELTA) {
                // Deltas vs previous time column (absolute deltas)
                $data = [];
                foreach ($row['cells'] as $idx => $cell) {
                    if ($idx >= count($this->columns) - 1) { break; }
                    $data[] = is_numeric($cell->deltaAbs) ? (float)$cell->deltaAbs : null;
                }
                $series[] = ['name' => $name.' Δ', 'data' => $data];
            }
            elseif ($this->mode === TimeGridMode::COMPARE) {
                // Primary
                $dataPrimary = [];
                // Baseline (compare)
                $dataBase    = [];
                foreach ($row['cells'] as $idx => $cell) {
                    if ($idx >= count($this->columns) - 1) { break; }
                    $dataPrimary[] = is_numeric($cell->value)   ? (float)$cell->value   : null;
                    $dataBase[]    = is_numeric($cell->compare) ? (float)$cell->compare : null;
                }
                $series[] = ['name' => $name.' (Primary)', 'data' => $dataPrimary];
                $series[] = ['name' => $name.' (Base)',    'data' => $dataBase];
            }
        }

        return $series;
    }
}
