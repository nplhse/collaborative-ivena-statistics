<?php

namespace App\Statistics\Infrastructure\Presets;

final class TransportTimeBucketPresets
{
    /**
     * @return list<array{value:string,label:string}>
     */
    public static function all(): array
    {
        return [
            ['value' => 'all',   'label' => 'All durations'],
            ['value' => '<10',   'label' => '< 10 min'],
            ['value' => '10-20', 'label' => '10–20 min'],
            ['value' => '20-30', 'label' => '20–30 min'],
            ['value' => '30-40', 'label' => '30–40 min'],
            ['value' => '40-50', 'label' => '40–50 min'],
            ['value' => '50-60', 'label' => '50–60 min'],
            ['value' => '>60',   'label' => '> 60 min'],
        ];
    }

    public static function isValid(string $bucket): bool
    {
        if ('all' === $bucket) {
            return true;
        }

        foreach (self::all() as $p) {
            if ($p['value'] === $bucket) {
                return true;
            }
        }

        return false;
    }
}
