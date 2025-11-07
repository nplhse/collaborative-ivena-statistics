<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('HourlyChart')]
final class HourlyChart
{
    /** @var list<string> */
    public array $labels = [];

    /** @var list<array{name:string,data:list<int>}> */
    public array $series = [];

    public int $height = 240;

    public string $domId = '';

    /**
     * @param list<string>                             $labels
     * @param list<array{name:string, data:list<int>}> $series
     */
    public function mount(array $labels, array $series, int $height = 240, ?string $domId = null): void
    {
        $this->labels = $labels;
        $this->series = $series;
        $this->height = $height;
        $this->domId = $domId ?? ('chart-hourly-'.bin2hex(random_bytes(4)));
    }

    public function hasData(): bool
    {
        if (empty($this->series)) {
            return false;
        }

        foreach ($this->series as $s) {
            $data = $s['data'] ?? null;

            if (null !== $data && [] !== $data && array_sum($data) > 0) {
                return true;
            }
        }

        return false;
    }
}
