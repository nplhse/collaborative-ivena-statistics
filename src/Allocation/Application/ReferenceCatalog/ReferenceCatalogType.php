<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Entity\State;

enum ReferenceCatalogType: string
{
    case State = 'state';
    case DispatchArea = 'dispatch-area';
    case Department = 'department';
    case Speciality = 'speciality';
    case Assignment = 'assignment';
    case Occasion = 'occasion';
    case Infection = 'infection';
    case SecondaryTransport = 'secondary-transport';
    case IndicationNormalized = 'indication-normalized';
    case IndicationRaw = 'indication-raw';
    case IndicationGroup = 'indication-group';
    case Hospital = 'hospital';

    public function sectionKey(): string
    {
        return match ($this) {
            self::State => 'states',
            self::DispatchArea => 'dispatch_areas',
            self::Department => 'departments',
            self::Speciality => 'specialities',
            self::Assignment => 'assignments',
            self::Occasion => 'occasions',
            self::Infection => 'infections',
            self::SecondaryTransport => 'secondary_transports',
            self::IndicationNormalized => 'indications_normalized',
            self::IndicationRaw => 'indications_raw',
            self::IndicationGroup => 'indication_groups',
            self::Hospital => 'hospitals',
        };
    }

    /**
     * @return class-string<State|DispatchArea|Department|Speciality|Assignment|Occasion|Infection|SecondaryTransport|IndicationNormalized|IndicationRaw|IndicationGroup|Hospital>
     */
    public function entityClass(): string
    {
        return match ($this) {
            self::State => State::class,
            self::DispatchArea => DispatchArea::class,
            self::Department => Department::class,
            self::Speciality => Speciality::class,
            self::Assignment => Assignment::class,
            self::Occasion => Occasion::class,
            self::Infection => Infection::class,
            self::SecondaryTransport => SecondaryTransport::class,
            self::IndicationNormalized => IndicationNormalized::class,
            self::IndicationRaw => IndicationRaw::class,
            self::IndicationGroup => IndicationGroup::class,
            self::Hospital => Hospital::class,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<self>
     */
    public static function importOrder(): array
    {
        return [
            self::State,
            self::DispatchArea,
            self::Department,
            self::Speciality,
            self::Assignment,
            self::Occasion,
            self::Infection,
            self::SecondaryTransport,
            self::IndicationNormalized,
            self::IndicationRaw,
            self::IndicationGroup,
            self::Hospital,
        ];
    }

    /**
     * @return list<self>
     */
    public static function parseList(?string $csv): array
    {
        if (null === $csv || '' === trim($csv)) {
            return self::importOrder();
        }

        $selected = [];
        foreach (explode(',', $csv) as $token) {
            $token = trim($token);
            if ('' === $token) {
                continue;
            }

            $type = self::tryFrom($token) ?? self::tryFrom(str_replace('_', '-', $token));
            if (!$type instanceof self) {
                throw new InvalidReferenceCatalogTypeException(sprintf('Unknown catalog type "%s". Expected comma-separated values from: %s', $token, implode(', ', array_map(static fn (self $case): string => $case->value, self::cases()))));
            }

            $selected[$type->value] = $type;
        }

        if ([] === $selected) {
            return self::importOrder();
        }

        $ordered = [];
        foreach (self::importOrder() as $type) {
            if (isset($selected[$type->value])) {
                $ordered[] = $type;
            }
        }

        return $ordered;
    }
}
