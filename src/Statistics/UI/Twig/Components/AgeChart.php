<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'AgeChart', template: '@Statistics/components/AgeChart.html.twig')]
final class AgeChart
{
    /** @var list<string> */
    public array $labels = [];

    /** @var list<array{name:string,data:list<int|float>}> */
    public array $series = [];

    public int $height = 240;

    public string $domId = '';

    /** Average age for the current scope/period (optional) */
    public ?float $mean = null;

    /**
     * @param list<string>                                   $labels
     * @param list<array{name:string, data:list<int|float>}> $series
     */
    public function mount(
        array $labels,
        array $series,
        int $height = 240,
        ?string $domId = null,
        ?float $mean = null,
    ): void {
        $this->labels = $labels;
        $this->series = $series;
        $this->height = $height;
        $this->domId = $domId ?? ('chart-age-'.bin2hex(random_bytes(4)));
        $this->mean = $mean;
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
