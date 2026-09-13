<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Application;

use App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto\ClosedDepartmentSliceData;

enum ClosedDepartmentNamedCard: string
{
    case Department = 'department';
    case Speciality = 'speciality';
    case Indication = 'indication';
    case Occasion = 'occasion';
    case Assignment = 'assignment';
    case Infection = 'infection';
    case DispatchArea = 'dispatch-area';

    /**
     * @return list<self>
     */
    public static function rankingCards(): array
    {
        return [
            self::Department,
            self::Speciality,
            self::Indication,
            self::Occasion,
            self::Assignment,
            self::Infection,
        ];
    }

    public function sliceKind(): string
    {
        return match ($this) {
            self::DispatchArea => 'dispatch_area',
            default => $this->value,
        };
    }

    public function titleKey(): string
    {
        return match ($this) {
            self::Department => 'stats.closed_department.section.who',
            self::Speciality => 'stats.closed_department.section.specialities',
            self::Indication => 'stats.closed_department.section.indications',
            self::Occasion => 'stats.closed_department.section.occasions',
            self::Assignment => 'stats.closed_department.section.assignments',
            self::Infection => 'stats.closed_department.section.infections',
            self::DispatchArea => 'stats.closed_department.section.dispatch_areas',
        };
    }

    public function testId(): string
    {
        return match ($this) {
            self::Department => 'stats-closed-department-departments',
            self::Speciality => 'stats-closed-department-specialities',
            self::Indication => 'stats-closed-department-indications',
            self::Occasion => 'stats-closed-department-occasions',
            self::Assignment => 'stats-closed-department-assignments',
            self::Infection => 'stats-closed-department-infections',
            self::DispatchArea => 'stats-closed-department-dispatch-areas',
        };
    }

    public function frameId(): string
    {
        return $this->testId();
    }

    public function exploreQueryKey(): string
    {
        return match ($this) {
            self::DispatchArea => 'dispatchArea',
            default => $this->value,
        };
    }

    public function topListReport(): ?string
    {
        return match ($this) {
            self::Department => 'top_departments',
            self::Speciality => 'top_specialities',
            self::Indication => 'top_diagnoses',
            self::Occasion => 'top_occasions',
            self::Assignment => 'top_assignments',
            self::Infection => 'top_infections',
            self::DispatchArea => null,
        };
    }

    /**
     * @return list<array{id: ?int, name: string, closed: int, total: int}>
     */
    public function rowsFromSlice(ClosedDepartmentSliceData $slice): array
    {
        return match ($this) {
            self::Department => $slice->departments,
            self::Speciality => $slice->specialities,
            self::Indication => $slice->indications,
            self::Occasion => $slice->occasions,
            self::Assignment => $slice->assignments,
            self::Infection => $slice->infections,
            self::DispatchArea => $slice->dispatchAreas,
        };
    }
}
