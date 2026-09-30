<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableValueResolver;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventChildPreview;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use PHPUnit\Framework\TestCase;

final class ClosureEventRowTest extends TestCase
{
    public function testSingleEventExposesUniqueChildFacetsToTheDataTable(): void
    {
        $row = $this->event(ClosureEventType::Single, [
            $this->child('Inner Medicine', 'Cardiology', 'emergency', 'no_bed_capacity', 'Ward 1'),
        ]);
        $resolver = new DataTableValueResolver();

        self::assertSame(['Inner Medicine'], $resolver->resolve($row, $this->column('specialities')));
        self::assertSame(['Cardiology'], $resolver->resolve($row, $this->column('departments')));
        self::assertSame(['emergency'], $resolver->resolve($row, $this->column('careLevels')));
        self::assertSame(['no_bed_capacity'], $resolver->resolve($row, $this->column('reasons')));
        self::assertSame(['Ward 1'], $resolver->resolve($row, $this->column('closureUnits')));
    }

    public function testGroupKeepsSharedClosureUnitAndCollectsDistinctDepartments(): void
    {
        $row = $this->event(ClosureEventType::Group, [
            $this->child('Surgery', 'Trauma', 'emergency', 'no_bed_capacity', 'Emergency ward'),
            $this->child('Surgery', 'Orthopedics', 'inpatient', 'no_bed_capacity', 'Emergency ward'),
        ]);
        $resolver = new DataTableValueResolver();

        self::assertSame(['Surgery'], $resolver->resolve($row, $this->column('specialities')));
        self::assertSame(['Trauma', 'Orthopedics'], $resolver->resolve($row, $this->column('departments')));
        self::assertSame(['Emergency ward'], $resolver->resolve($row, $this->column('closureUnits')));
        self::assertSame(['no_bed_capacity'], $resolver->resolve($row, $this->column('reasons')));
    }

    public function testEventTypeHelpersDistinguishGroupClusterAndSingle(): void
    {
        $group = $this->event(ClosureEventType::Group, [
            $this->child('Surgery', 'Trauma', 'emergency', 'no_bed_capacity', 'Emergency ward'),
        ]);
        $cluster = $this->event(ClosureEventType::Cluster, [
            $this->child('Surgery', 'Trauma', 'emergency', 'no_bed_capacity', null),
        ]);
        $single = $this->event(ClosureEventType::Single, [
            $this->child('Surgery', 'Trauma', 'emergency', 'no_bed_capacity', null),
        ]);

        self::assertTrue($group->grouped());
        self::assertTrue($group->hasChildren());
        self::assertFalse($group->clustered());
        self::assertTrue($cluster->clustered());
        self::assertTrue($cluster->hasChildren());
        self::assertFalse($single->grouped());
        self::assertFalse($single->clustered());
        self::assertFalse($single->hasChildren());
    }

    /**
     * @param list<ClosureEventChildPreview> $children
     */
    private function event(ClosureEventType $type, array $children): ClosureEventRow
    {
        return new ClosureEventRow(
            'group:1:demo',
            $type,
            1,
            'Demo Hospital',
            ClosureEventType::Group === $type ? 'demo' : null,
            new \DateTimeImmutable('2026-07-15 10:00:00'),
            new \DateTimeImmutable('2026-07-15 12:00:00'),
            \count($children),
            120,
            120,
            1_440,
            $children,
        );
    }

    private function child(
        string $speciality,
        string $department,
        string $careLevel,
        string $reason,
        ?string $unit,
    ): ClosureEventChildPreview {
        return new ClosureEventChildPreview(
            1,
            $speciality,
            $department,
            $careLevel,
            $reason,
            $unit,
            new \DateTimeImmutable('2026-07-15 10:00:00'),
            new \DateTimeImmutable('2026-07-15 12:00:00'),
        );
    }

    private function column(string $key): DataTableColumn
    {
        return DataTableColumn::fromArray([
            'key' => $key,
            'label' => $key,
            'type' => 'custom',
        ]);
    }
}
