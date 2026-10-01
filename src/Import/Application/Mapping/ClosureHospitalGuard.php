<?php

declare(strict_types=1);

namespace App\Import\Application\Mapping;

use App\Import\Application\Exception\ImportException;

final class ClosureHospitalGuard
{
    /**
     * @param iterable<array<string, string>> $rows
     *
     * @return list<string> first-seen non-empty short names
     */
    public function distinctShortNames(iterable $rows): array
    {
        $seen = [];
        $ordered = [];

        foreach ($rows as $row) {
            $raw = $row['krankenhaus_kurzname'] ?? null;
            if (!\is_string($raw)) {
                continue;
            }

            $display = trim($raw);
            $key = $this->normalize($display);
            if ('' === $key || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $ordered[] = $display;
        }

        return $ordered;
    }

    /**
     * @param array<int, string> $hospitalNames      hospital id => catalog name
     * @param list<string>       $distinctShortNames
     */
    public function profile(int $selectedHospitalId, array $hospitalNames, array $distinctShortNames): ClosureHospitalProfile
    {
        if (!isset($hospitalNames[$selectedHospitalId])) {
            throw new \InvalidArgumentException('Selected hospital is missing from the catalog.');
        }

        $selectedDisplay = $hospitalNames[$selectedHospitalId];
        $selectedNormalized = $this->normalize($selectedDisplay);

        /** @var array<string, list<int>> $idsByName */
        $idsByName = [];
        /** @var array<string, string> $catalogDisplay */
        $catalogDisplay = [];
        foreach ($hospitalNames as $id => $name) {
            $key = $this->normalize($name);
            if ('' === $key) {
                continue;
            }

            $idsByName[$key][] = $id;
            $catalogDisplay[$key] ??= $name;
        }

        $displays = [];
        $normalized = [];
        foreach ($distinctShortNames as $shortName) {
            $display = trim($shortName);
            $key = $this->normalize($display);
            if ('' === $key || isset($displays[$key])) {
                continue;
            }

            $displays[$key] = $display;
            $normalized[] = $key;
        }

        $distinctDisplays = array_values($displays);
        $multiple = \count($normalized) > 1;
        $differingFileLabel = !$multiple && 1 === \count($normalized) && $normalized[0] !== $selectedNormalized
            ? $distinctDisplays[0]
            : null;

        return new ClosureHospitalProfile(
            selectedHospitalId: $selectedHospitalId,
            selectedDisplayName: $selectedDisplay,
            selectedNormalizedName: $selectedNormalized,
            multiple: $multiple,
            distinctDisplays: $distinctDisplays,
            normalizedShortNames: $normalized,
            hospitalIdsByNormalizedName: $idsByName,
            catalogDisplayByNormalized: $catalogDisplay,
            differingFileLabel: $differingFileLabel,
        );
    }

    public function assertRow(ClosureHospitalProfile $profile, ?string $shortName): void
    {
        if (!$profile->multiple) {
            $this->assertSingleDesignation($profile, $shortName);

            return;
        }

        $actual = $this->normalize($shortName);
        if ('' !== $profile->selectedNormalizedName && $actual === $profile->selectedNormalizedName) {
            return;
        }

        throw new ImportException(message: \sprintf('The file contains more than one hospital name (%s). This row says "%s" and does not match the selected hospital "%s". Split the file and import each hospital separately.', implode(', ', $profile->distinctDisplays), $shortName ?? '', $profile->selectedDisplayName), field: 'hospital', value: $shortName, codeStr: 'HOSPITAL_MISMATCH');
    }

    private function assertSingleDesignation(ClosureHospitalProfile $profile, ?string $shortName): void
    {
        if (1 !== \count($profile->normalizedShortNames)) {
            return;
        }

        $only = $profile->normalizedShortNames[0];
        $ids = $profile->hospitalIdsByNormalizedName[$only] ?? [];
        if (1 !== \count($ids) || $ids[0] === $profile->selectedHospitalId) {
            return;
        }

        $fileLabel = $profile->distinctDisplays[0] ?? ($shortName ?? '');
        $otherName = $profile->catalogDisplayByNormalized[$only] ?? $fileLabel;

        throw new ImportException(message: \sprintf('The file names hospital "%s", which matches "%s" rather than the hospital selected for this import ("%s"). Start the import for "%s", or export the closure list for the selected hospital.', $fileLabel, $otherName, $profile->selectedDisplayName, $otherName), field: 'hospital', value: $shortName, codeStr: 'HOSPITAL_CONFLICT');
    }

    private function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $collapsed = preg_replace('/\s+/', ' ', $value);

        return $collapsed ?? $value;
    }
}
