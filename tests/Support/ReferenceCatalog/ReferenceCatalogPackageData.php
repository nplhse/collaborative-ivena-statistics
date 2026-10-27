<?php

declare(strict_types=1);

namespace App\Tests\Support\ReferenceCatalog;

use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * A small, complete and valid reference catalog package as array, for tests that change one part of it.
 */
final class ReferenceCatalogPackageData
{
    public const string STATE = '0192d6a1-0000-7000-8000-000000000001';
    public const string OTHER_STATE = '0192d6a1-0000-7000-8000-000000000002';
    public const string AREA = '0192d6a1-0000-7000-8000-000000000011';
    public const string OTHER_AREA = '0192d6a1-0000-7000-8000-000000000012';
    public const string DEPARTMENT = '0192d6a1-0000-7000-8000-000000000021';
    public const string SPECIALITY = '0192d6a1-0000-7000-8000-000000000031';
    public const string NORMALIZED = '0192d6a1-0000-7000-8000-000000000041';
    public const string OTHER_NORMALIZED = '0192d6a1-0000-7000-8000-000000000042';
    public const string RAW = '0192d6a1-0000-7000-8000-000000000051';
    public const string UNMAPPED_RAW = '0192d6a1-0000-7000-8000-000000000052';
    public const string GROUP = '0192d6a1-0000-7000-8000-000000000061';
    public const string HOSPITAL = '0192d6a1-0000-7000-8000-000000000071';

    /**
     * @return array<string, mixed>
     */
    public static function complete(): array
    {
        $data = [
            'meta' => [
                'format' => 'ivena-reference-catalog',
                'version' => 1,
                'exported_at' => '2026-10-26T10:00:00+01:00',
                'complete' => true,
                'types' => ReferenceCatalogType::values(),
                'counts' => [],
                'checksum' => ['algorithm' => 'sha256', 'value' => str_repeat('0', 64)],
                'source_environment' => 'test',
                'sources' => [['name' => 'PZC', 'version' => '1.3.11', 'date' => '2025-06-13']],
            ],
            'states' => [
                ['id' => self::STATE, 'name' => 'Hessen'],
                ['id' => self::OTHER_STATE, 'name' => 'Bayern'],
            ],
            'dispatch_areas' => [
                ['id' => self::AREA, 'name' => 'Frankfurt', 'state' => self::STATE],
                ['id' => self::OTHER_AREA, 'name' => 'Würzburg', 'state' => self::OTHER_STATE],
            ],
            'departments' => [
                [
                    'id' => self::DEPARTMENT,
                    'name' => 'Allgemeine Innere Medizin',
                    'aliases' => [
                        ['name' => 'Allgem. Innere Medizin', 'classification' => 'faulty_catalog', 'source' => 'local-catalog', 'note' => 'typo in source', 'valid_from' => '2024-01-01', 'valid_to' => null],
                    ],
                ],
            ],
            'specialities' => [
                ['id' => self::SPECIALITY, 'name' => 'Innere Medizin', 'aliases' => []],
            ],
            'assignments' => [['id' => '0192d6a1-0000-7000-8000-000000000081', 'name' => 'Patient']],
            'occasions' => [['id' => '0192d6a1-0000-7000-8000-000000000082', 'name' => 'aus Klinik']],
            'infections' => [['id' => '0192d6a1-0000-7000-8000-000000000083', 'name' => 'MRSA']],
            'secondary_transports' => [['id' => '0192d6a1-0000-7000-8000-000000000084', 'name' => 'Diagnostik']],
            'indications_normalized' => [
                ['id' => self::NORMALIZED, 'code' => '143', 'name' => 'vaECMO Abholung', 'note' => null],
                ['id' => self::OTHER_NORMALIZED, 'code' => '143', 'name' => 'vaECMO Abholung (Zentrum)', 'note' => 'split of one code'],
            ],
            'indications_raw' => [
                [
                    'id' => self::RAW,
                    'code' => '143',
                    'name' => 'ECMO Abholung',
                    'target' => self::NORMALIZED,
                    'review' => ['status' => 'matched', 'comment' => 'checked', 'reviewed_at' => '2026-09-01T12:00:00+02:00'],
                    'first_matched_at' => '2026-08-30T09:00:00+02:00',
                ],
                [
                    'id' => self::UNMAPPED_RAW,
                    'code' => '322',
                    'name' => '',
                    'target' => null,
                    'review' => ['status' => 'unreviewed'],
                ],
            ],
            'indication_groups' => [
                ['id' => self::GROUP, 'name' => 'ECMO', 'members' => [self::NORMALIZED], 'category' => 'transfer', 'description' => null, 'sort_order' => 1],
            ],
            'hospitals' => [
                [
                    'id' => self::HOSPITAL,
                    'name' => 'Testklinikum',
                    'state' => self::STATE,
                    'area' => self::AREA,
                    'tier' => 'Full',
                    'size' => 'Large',
                    'beds' => 800,
                    'location' => 'Urban',
                    'address' => ['street' => 'Teststraße 1', 'postal_code' => '60311', 'city' => 'Frankfurt am Main', 'state' => 'Hessen', 'country' => 'Deutschland'],
                    'coordinates' => ['lat' => 50.11, 'lng' => 8.68],
                ],
            ],
        ];

        return self::withCounts($data);
    }

    /**
     * Sets `meta.counts` to the number of entries of every listed type.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function withCounts(array $data): array
    {
        /** @var list<string> $types */
        $types = $data['meta']['types'];
        $counts = [];
        foreach ($types as $type) {
            $section = $data[ReferenceCatalogType::from($type)->sectionKey()] ?? [];
            $counts[$type] = \is_array($section) ? \count($section) : 0;
        }
        $data['meta']['counts'] = $counts;

        return $data;
    }

    /**
     * Sets `meta.checksum` to the checksum of the current content.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function withChecksum(array $data, DenormalizerInterface $denormalizer, ReferenceCatalogChecksum $checksum): array
    {
        $package = $denormalizer->denormalize($data, ReferenceCatalogPackage::class, 'json');
        \assert($package instanceof ReferenceCatalogPackage);
        $data['meta']['checksum']['value'] = $checksum->compute($package);

        return $data;
    }
}
