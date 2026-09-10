<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

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

    public function yamlKey(): string
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
