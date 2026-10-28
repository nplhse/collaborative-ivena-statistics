<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Scenario;

/**
 * The scenarios `app:generate:scenario` knows. Shares are coarse general knowledge, not real data.
 */
final class ScenarioCatalog
{
    /**
     * @return array<string, ScenarioDefinition>
     */
    public static function all(): array
    {
        return [
            'mci' => new ScenarioDefinition(
                name: 'mci',
                description: 'Mass casualty incident: 8–20 urgency-1 patients within 60–90 minutes, spread over the hospitals of the area',
                days: [1, 1],
                indications: ['211' => 0.40, '224' => 0.30, '272' => 0.30],
                mciPatients: [8, 20],
            ),
            'icy-road' => new ScenarioDefinition(
                name: 'icy-road',
                description: 'Icy-road day: about 45 % more allocations, mostly falls and fractures of older patients',
                days: [1, 1],
                indications: ['272' => 0.45, '273' => 0.25, '224' => 0.20, '211' => 0.10],
                extraShare: 0.45,
            ),
            'flu-wave' => new ScenarioDefinition(
                name: 'flu-wave',
                description: 'Flu wave of 2–4 weeks: about 25 % more respiratory cases and more internal medicine closures',
                days: [14, 28],
                indications: ['315' => 0.50, '311' => 0.40, '329' => 0.10],
                extraShare: 0.25,
                closedDepartments: ['Pulmologie', 'Infektiologie', 'Gastroenterologie'],
                closuresPerHospitalAndWeek: 1.5,
            ),
            'heat' => new ScenarioDefinition(
                name: 'heat',
                description: 'Heat days: 3–5 days with about 30 % more collapses and internal emergencies of older patients',
                days: [3, 5],
                indications: ['322' => 0.50, '329' => 0.30, '414' => 0.20],
                extraShare: 0.30,
            ),
        ];
    }

    public static function get(string $name): ScenarioDefinition
    {
        return self::all()[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown scenario "%s". Known: %s.', $name, implode(', ', array_keys(self::all()))));
    }
}
