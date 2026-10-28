<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\Allocation\Infrastructure\Faker\Provider\PzcCodes;
use App\DataFixtures\Generator\Application\Scenario\ScenarioCatalog;
use PHPUnit\Framework\TestCase;

final class ScenarioCatalogTest extends TestCase
{
    public function testEveryScenarioUsesUniquePzcWordingsAndCatalogDepartments(): void
    {
        $counts = array_count_values(array_column(PzcCodes::all(), 'name'));
        foreach (ScenarioCatalog::all() as $name => $scenario) {
            self::assertSame($name, $scenario->name);
            self::assertEqualsWithDelta(1.0, array_sum($scenario->indications), 0.001, $name);
            foreach (array_keys($scenario->indications) as $code) {
                self::assertSame(1, $counts[PzcCodes::name((string) $code)] ?? 0, sprintf('%s: %s must have a unique wording, the sampler finds it by name', $name, $code));
            }
            foreach ($scenario->closedDepartments as $department) {
                self::assertContains($department, MedicalSpecialities::unitNames(), $name);
            }
            self::assertLessThanOrEqual($scenario->days[1], $scenario->days[0], $name.': min days <= max days');
        }
    }

    public function testOnlyTheMassCasualtyIncidentWritesMciPatients(): void
    {
        self::assertTrue(ScenarioCatalog::get('mci')->isMci());
        self::assertFalse(ScenarioCatalog::get('icy-road')->isMci());

        $this->expectException(\InvalidArgumentException::class);
        ScenarioCatalog::get('volcano');
    }
}
