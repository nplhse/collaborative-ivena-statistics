<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Scenario;

/**
 * One scenario a preset lays over its data: where and when (PresetScenarioPlanner).
 */
final readonly class PresetScenario
{
    /**
     * @param list<int> $hospitalIds participating hospitals, in the order the MCI patients are spread
     * @param string    $area        key of the scenario's area stream
     */
    public function __construct(
        public ScenarioDefinition $definition,
        public \DateTimeImmutable $startDay,
        public array $hospitalIds,
        public string $area,
    ) {
    }
}
