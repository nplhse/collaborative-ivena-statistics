<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\Domain\Model\TransportTimeStatsView;
use App\Statistics\Infrastructure\Presets\TransportTimeMetricPresets;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent(name: 'TransportTimeChart', template: '@Statistics/components/TransportTimeChart.html.twig')]
final class TransportTimeChart
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public TransportTimeStatsView $viewModel;

    /** @var list<string> */
    public array $labels = [];

    /** @var list<array{name:string,data:list<int|float>}> */
    public array $series = [];

    public int $height = 240;

    public string $domId = '';

    /**
     * View mode: "int" for counts, "pct" for percentages.
     */
    public string $view = 'int';

    /**
     * Current preset: 'total' | 'gender' | 'urgency' | 'transport'.
     */
    public string $preset = 'total';

    /** Mean is available but currently not displayed in the chart. */
    public ?float $mean = null;

    public function mount(
        TransportTimeStatsView $viewModel,
        int $height = 240,
        ?string $domId = null,
        string $view = 'int',
        string $preset = 'total',
    ): void {
        $this->viewModel = $viewModel;
        $this->height = $height;
        $this->domId = $domId ?? ('chart-transport-'.bin2hex(random_bytes(4)));
        $this->view = $view;
        $this->preset = $preset;
    }

    #[PostMount]
    public function postMount(): void
    {
        $vm = $this->viewModel;

        // Labels = buckets
        $labels = array_values($vm->getBuckets());
        $this->labels = $labels;

        // Which metrics should we show for the current preset?
        $metricDefs = TransportTimeMetricPresets::metricsFor($this->preset);

        // Index rows by ID
        $rowsById = [];
        foreach ($vm->getRows() as $row) {
            $rowsById[$row->getId()] = $row;
        }

        // Build series based on the preset
        $this->series = [];
        foreach ($metricDefs as $def) {
            $row = $rowsById[$def['id']] ?? null;
            if (null === $row) {
                continue;
            }

            $data = [];
            foreach ($row->getValues() as $value) {
                // Always push counts; percentage mode is handled via stacked chart + tooltip/y-axis.
                $data[] = $value['count'] ?? 0;
            }

            $this->series[] = [
                'name' => $def['name'],
                'data' => $data,
            ];
        }

        $this->mean = $vm->getMean();
    }

    public function hasData(): bool
    {
        if ([] === $this->series) {
            return false;
        }

        foreach ($this->series as $s) {
            $data = $s['data'] ?? null;
            if (is_array($data) && [] !== $data && array_sum(array_map('floatval', $data)) > 0.0) {
                return true;
            }
        }

        return false;
    }
}
