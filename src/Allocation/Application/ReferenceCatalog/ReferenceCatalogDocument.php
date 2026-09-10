<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

/**
 * @phpstan-type DispatchAreaRow array{name: string, state: ?string}
 * @phpstan-type IndicationRow array{code: string, name: string}
 * @phpstan-type IndicationGroupRow array{name: string, category: ?string, codes: list<string>}
 * @phpstan-type HospitalAddressRow array{street: string, city: string, state: string, postalCode: string, country: string}
 * @phpstan-type HospitalRow array{
 *     name: string,
 *     state: string,
 *     area: string,
 *     participating: bool,
 *     tier: ?string,
 *     size: string,
 *     beds: int,
 *     location: string,
 *     address: HospitalAddressRow
 * }
 */
final class ReferenceCatalogDocument
{
    /**
     * @param list<string>             $states
     * @param list<DispatchAreaRow>    $dispatchAreas
     * @param list<string>             $departments
     * @param list<string>             $specialities
     * @param list<string>             $assignments
     * @param list<string>             $occasions
     * @param list<string>             $infections
     * @param list<string>             $secondaryTransports
     * @param list<IndicationRow>      $indicationsNormalized
     * @param list<IndicationRow>      $indicationsRaw
     * @param list<IndicationGroupRow> $indicationGroups
     * @param list<HospitalRow>        $hospitals
     */
    public function __construct(
        public array $states = [],
        public array $dispatchAreas = [],
        public array $departments = [],
        public array $specialities = [],
        public array $assignments = [],
        public array $occasions = [],
        public array $infections = [],
        public array $secondaryTransports = [],
        public array $indicationsNormalized = [],
        public array $indicationsRaw = [],
        public array $indicationGroups = [],
        public array $hospitals = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            states: self::stringList($data['states'] ?? []),
            dispatchAreas: self::dispatchAreaRows($data['dispatch_areas'] ?? []),
            departments: self::stringList($data['departments'] ?? []),
            specialities: self::stringList($data['specialities'] ?? []),
            assignments: self::stringList($data['assignments'] ?? []),
            occasions: self::stringList($data['occasions'] ?? []),
            infections: self::stringList($data['infections'] ?? []),
            secondaryTransports: self::stringList($data['secondary_transports'] ?? []),
            indicationsNormalized: self::indicationRows($data['indications_normalized'] ?? []),
            indicationsRaw: self::indicationRows($data['indications_raw'] ?? []),
            indicationGroups: self::indicationGroupRows($data['indication_groups'] ?? []),
            hospitals: self::hospitalRows($data['hospitals'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'states' => $this->states,
            'dispatch_areas' => array_map(
                static fn (array $row): array => [
                    'name' => $row['name'],
                    'state' => $row['state'],
                ],
                $this->dispatchAreas,
            ),
            'departments' => $this->departments,
            'specialities' => $this->specialities,
            'assignments' => $this->assignments,
            'occasions' => $this->occasions,
            'infections' => $this->infections,
            'secondary_transports' => $this->secondaryTransports,
            'indications_normalized' => $this->indicationsNormalized,
            'indications_raw' => $this->indicationsRaw,
            'indication_groups' => $this->indicationGroups,
            'hospitals' => $this->hospitals,
        ];
    }

    /**
     * @param list<ReferenceCatalogType> $types
     */
    public function withTypes(array $types): self
    {
        $keep = [];
        foreach ($types as $type) {
            $keep[$type->value] = true;
        }

        $empty = new self();

        return new self(
            states: isset($keep[ReferenceCatalogType::State->value]) ? $this->states : $empty->states,
            dispatchAreas: isset($keep[ReferenceCatalogType::DispatchArea->value]) ? $this->dispatchAreas : $empty->dispatchAreas,
            departments: isset($keep[ReferenceCatalogType::Department->value]) ? $this->departments : $empty->departments,
            specialities: isset($keep[ReferenceCatalogType::Speciality->value]) ? $this->specialities : $empty->specialities,
            assignments: isset($keep[ReferenceCatalogType::Assignment->value]) ? $this->assignments : $empty->assignments,
            occasions: isset($keep[ReferenceCatalogType::Occasion->value]) ? $this->occasions : $empty->occasions,
            infections: isset($keep[ReferenceCatalogType::Infection->value]) ? $this->infections : $empty->infections,
            secondaryTransports: isset($keep[ReferenceCatalogType::SecondaryTransport->value]) ? $this->secondaryTransports : $empty->secondaryTransports,
            indicationsNormalized: isset($keep[ReferenceCatalogType::IndicationNormalized->value]) ? $this->indicationsNormalized : $empty->indicationsNormalized,
            indicationsRaw: isset($keep[ReferenceCatalogType::IndicationRaw->value]) ? $this->indicationsRaw : $empty->indicationsRaw,
            indicationGroups: isset($keep[ReferenceCatalogType::IndicationGroup->value]) ? $this->indicationGroups : $empty->indicationGroups,
            hospitals: isset($keep[ReferenceCatalogType::Hospital->value]) ? $this->hospitals : $empty->hospitals,
        );
    }

    /**
     * @param list<ReferenceCatalogType> $types
     */
    public function withoutEmptySections(array $types): self
    {
        return $this->withTypes(array_values(array_filter(
            $types,
            fn (ReferenceCatalogType $type): bool => !$this->isSectionEmpty($type),
        )));
    }

    public function isSectionEmpty(ReferenceCatalogType $type): bool
    {
        return match ($type) {
            ReferenceCatalogType::State => [] === $this->states,
            ReferenceCatalogType::DispatchArea => [] === $this->dispatchAreas,
            ReferenceCatalogType::Department => [] === $this->departments,
            ReferenceCatalogType::Speciality => [] === $this->specialities,
            ReferenceCatalogType::Assignment => [] === $this->assignments,
            ReferenceCatalogType::Occasion => [] === $this->occasions,
            ReferenceCatalogType::Infection => [] === $this->infections,
            ReferenceCatalogType::SecondaryTransport => [] === $this->secondaryTransports,
            ReferenceCatalogType::IndicationNormalized => [] === $this->indicationsNormalized,
            ReferenceCatalogType::IndicationRaw => [] === $this->indicationsRaw,
            ReferenceCatalogType::IndicationGroup => [] === $this->indicationGroups,
            ReferenceCatalogType::Hospital => [] === $this->hospitals,
        };
    }

    /**
     * @return list<string>
     */
    public function names(string $section): array
    {
        $section = str_replace('.yaml', '', $section);

        return match ($section) {
            'departments' => $this->departments,
            'specialities' => $this->specialities,
            'assignments' => $this->assignments,
            'occasions' => $this->occasions,
            'infections' => $this->infections,
            'secondary_transports' => $this->secondaryTransports,
            default => throw new \InvalidArgumentException(sprintf('Unknown name section "%s".', $section)),
        };
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $item) {
            if (!\is_string($item) && !\is_int($item)) {
                continue;
            }

            $name = trim((string) $item);
            if ('' === $name) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return list<DispatchAreaRow>
     */
    private static function dispatchAreaRows(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ('' === $name) {
                continue;
            }

            $stateRaw = $item['state'] ?? null;
            $state = \is_string($stateRaw) ? trim($stateRaw) : '';

            $rows[] = [
                'name' => $name,
                'state' => '' === $state ? null : $state,
            ];
        }

        return $rows;
    }

    /**
     * @return list<IndicationRow>
     */
    private static function indicationRows(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $code = trim((string) ($item['code'] ?? ''));
            if ('' === $code) {
                continue;
            }

            $rows[] = ['code' => $code, 'name' => trim((string) ($item['name'] ?? ''))];
        }

        return $rows;
    }

    /**
     * @return list<IndicationGroupRow>
     */
    private static function indicationGroupRows(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ('' === $name) {
                continue;
            }

            $codes = [];
            foreach ($item['codes'] ?? [] as $code) {
                $codes[] = (string) $code;
            }

            $categoryRaw = $item['category'] ?? null;
            $category = \is_string($categoryRaw) ? trim($categoryRaw) : '';

            $rows[] = [
                'name' => $name,
                'category' => '' === $category ? null : $category,
                'codes' => $codes,
            ];
        }

        return $rows;
    }

    /**
     * @return list<HospitalRow>
     */
    private static function hospitalRows(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $state = trim((string) ($item['state'] ?? ''));
            $area = trim((string) ($item['area'] ?? ''));
            if (in_array('', [$name, $state, $area], true)) {
                continue;
            }

            $addressData = \is_array($item['address'] ?? null) ? $item['address'] : [];

            $rows[] = [
                'name' => $name,
                'state' => $state,
                'area' => $area,
                'participating' => (bool) ($item['participating'] ?? false),
                'tier' => isset($item['tier']) && \is_string($item['tier']) ? $item['tier'] : null,
                'size' => (string) ($item['size'] ?? ''),
                'beds' => (int) ($item['beds'] ?? 0),
                'location' => (string) ($item['location'] ?? ''),
                'address' => [
                    'street' => (string) ($addressData['street'] ?? ''),
                    'city' => (string) ($addressData['city'] ?? ''),
                    'state' => (string) ($addressData['state'] ?? ''),
                    'postalCode' => (string) ($addressData['postalCode'] ?? ''),
                    'country' => (string) ($addressData['country'] ?? ''),
                ],
            ];
        }

        return $rows;
    }
}
