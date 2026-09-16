<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use App\Allocation\Application\Explore\Catalog\CatalogDimensionKey;

enum InsightDimensionKey: string
{
    case Indications = 'indications';
    case IndicationGroups = 'indication-groups';
    case Specialities = 'specialities';
    case Assignments = 'assignments';
    case Departments = 'departments';
    case Occasions = 'occasions';
    case Infections = 'infections';
    case SecondaryTransports = 'secondary-transports';

    public static function routeRequirement(): string
    {
        return implode('|', array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        ));
    }

    public function projectionColumn(): string
    {
        return match ($this) {
            self::Indications, self::IndicationGroups => 'indication_normalized_id',
            self::Specialities => 'speciality_id',
            self::Assignments => 'assignment_id',
            self::Departments => 'department_id',
            self::Occasions => 'occasion_id',
            self::Infections => 'infection_id',
            self::SecondaryTransports => 'secondary_transport_id',
        };
    }

    public function projectionJoinProperty(): string
    {
        return match ($this) {
            self::Indications, self::IndicationGroups => 'indicationNormalizedId',
            self::Specialities => 'specialityId',
            self::Assignments => 'assignmentId',
            self::Departments => 'departmentId',
            self::Occasions => 'occasionId',
            self::Infections => 'infectionId',
            self::SecondaryTransports => 'secondaryTransportId',
        };
    }

    public function catalogDimension(): ?CatalogDimensionKey
    {
        return match ($this) {
            self::Indications => CatalogDimensionKey::Indication,
            self::IndicationGroups => null,
            self::Specialities => CatalogDimensionKey::Speciality,
            self::Assignments => CatalogDimensionKey::Assignment,
            self::Departments => CatalogDimensionKey::Department,
            self::Occasions => CatalogDimensionKey::Occasion,
            self::Infections => CatalogDimensionKey::Infection,
            self::SecondaryTransports => CatalogDimensionKey::SecondaryTransport,
        };
    }

    public function topListKey(): ?string
    {
        return match ($this) {
            self::Indications => 'top_diagnoses',
            self::IndicationGroups => null,
            self::Specialities => 'top_specialities',
            self::Assignments => 'top_assignments',
            self::Departments => 'top_departments',
            self::Occasions => 'top_occasions',
            self::Infections => 'top_infections',
            self::SecondaryTransports => 'top_secondary_transports',
        };
    }

    public function compareFamily(): self
    {
        return match ($this) {
            self::IndicationGroups => self::Indications,
            default => $this,
        };
    }
}
