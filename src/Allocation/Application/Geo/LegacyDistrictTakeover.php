<?php

declare(strict_types=1);

namespace App\Allocation\Application\Geo;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Domain\Entity\DistrictLegacyTakeover;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-time, offline takeover of the district geography that used to live in repository files (#1406, ADR 041).
 * Repeatable after an abort; never overwrites an existing assignment or geometry.
 *
 * @psalm-type TransferArea = array{name: string, ags: list<string>, geometry_sha256: string}
 * @psalm-type TransferFeature = array{properties: array{ags: string, name: string, kind: string, vintage: int}, geometry: array<string, mixed>}
 * @psalm-type Transfer = array{areas: list<TransferArea>, features: list<TransferFeature>}
 */
final readonly class LegacyDistrictTakeover
{
    private const string PROVIDER = 'legacy_vg250';
    private const string LICENCE = 'dl-de/by-2-0';

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/resources/geo/legacy-districts-1406.geojson')]
        private string $transferFile,
    ) {
    }

    public function import(): LegacyDistrictTakeoverReport
    {
        if ($this->isComplete()) {
            return new LegacyDistrictTakeoverReport(LegacyDistrictTakeoverOutcome::AlreadyComplete);
        }

        $transfer = $this->readTransfer();
        $areaIds = $this->resolveAreaIds($transfer);
        if ($areaIds instanceof LegacyDistrictTakeoverReport) {
            return $areaIds;
        }

        $areaIdByAgs = [];
        foreach ($transfer['areas'] as $area) {
            foreach ($area['ags'] as $ags) {
                $areaIdByAgs[$ags] = $areaIds[$area['name']];
            }
        }

        $created = $assigned = $stored = 0;
        $problems = [];
        foreach ($transfer['features'] as $feature) {
            $ags = $feature['properties']['ags'];
            $areaId = $areaIdByAgs[$ags] ?? null;
            if (null === $areaId) {
                $problems[] = sprintf('District %s belongs to no dispatch area in the transfer file.', $ags);

                continue;
            }

            // One transaction per district, so an aborted run keeps its progress and can be repeated.
            $this->entityManager->wrapInTransaction(function () use ($feature, $ags, $areaId, &$created, &$assigned, &$stored, &$problems): void {
                $district = $this->entityManager->getRepository(District::class)->findOneBy(['ags' => $ags]);
                $geometry = null;

                if (!$district instanceof District) {
                    $district = new District($ags, $feature['properties']['name'])
                        ->setKind($feature['properties']['kind'])
                        ->setDispatchArea($this->entityManager->getReference(DispatchArea::class, $areaId));
                    $this->entityManager->persist($district);
                    ++$created;
                } else {
                    $geometry = $this->entityManager->find(DistrictGeometry::class, $district->getId());
                    $currentArea = $district->getDispatchArea();

                    if (!$currentArea instanceof DispatchArea) {
                        $district->setDispatchArea($this->entityManager->getReference(DispatchArea::class, $areaId));
                        ++$assigned;
                    } elseif ($currentArea->getId() !== $areaId) {
                        $problems[] = sprintf('District %s is assigned to another dispatch area; the assignment was kept.', $ags);
                    }
                }

                if (!$geometry instanceof DistrictGeometry) {
                    $geometry = new DistrictGeometry($district);
                    $this->entityManager->persist($geometry);
                }

                $storedGeometry = $geometry->getGeometry();
                if (null === $storedGeometry) {
                    $vintage = $feature['properties']['vintage'];
                    $geometry->storeGeometry(
                        json_encode($feature['geometry'], \JSON_THROW_ON_ERROR),
                        self::PROVIDER,
                        self::LICENCE,
                        sprintf('© BKG (%d) %s', $vintage, self::LICENCE),
                        null,
                    );
                    ++$stored;
                } elseif ($this->storedHash([$storedGeometry]) !== self::geometryHash([$feature['geometry']])) {
                    $problems[] = sprintf('District %s has a different geometry; it was kept.', $ags);
                }
            });
            $this->entityManager->clear();
        }

        return new LegacyDistrictTakeoverReport(LegacyDistrictTakeoverOutcome::Done, $created, $assigned, $stored, $problems);
    }

    /**
     * Compares the stored data with the reference data of the transfer file. Stores the completion marker on success.
     */
    public function verify(): LegacyDistrictTakeoverReport
    {
        if ($this->isComplete()) {
            return new LegacyDistrictTakeoverReport(LegacyDistrictTakeoverOutcome::AlreadyComplete);
        }

        $transfer = $this->readTransfer();
        $areaIds = $this->resolveAreaIds($transfer);
        if ($areaIds instanceof LegacyDistrictTakeoverReport) {
            return $areaIds;
        }

        $problems = [];
        foreach ($transfer['areas'] as $area) {
            /** @var list<array{ags: string, geometry: string|null}> $rows */
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT d.ags, g.geometry FROM district d LEFT JOIN district_geometry g ON g.district_id = d.id WHERE d.dispatch_area_id = ? ORDER BY d.ags',
                [$areaIds[$area['name']]],
            );

            $expected = $area['ags'];
            sort($expected);
            if (array_column($rows, 'ags') !== $expected) {
                $problems[] = sprintf('"%s": expected districts %s.', $area['name'], implode(', ', $expected));

                continue;
            }

            $geometries = array_column($rows, 'geometry');
            if (\in_array(null, $geometries, true)) {
                $problems[] = sprintf('"%s": a district has no geometry.', $area['name']);

                continue;
            }

            /** @var list<string> $geometries */
            if ($this->storedHash($geometries) !== $area['geometry_sha256']) {
                $problems[] = sprintf('"%s": the geometry differs from the reference.', $area['name']);
            }
        }

        if ([] !== $problems) {
            return new LegacyDistrictTakeoverReport(LegacyDistrictTakeoverOutcome::Failed, problems: $problems);
        }

        $this->entityManager->persist(new DistrictLegacyTakeover((string) hash_file('sha256', $this->transferFile)));
        $this->entityManager->flush();

        return new LegacyDistrictTakeoverReport(LegacyDistrictTakeoverOutcome::Done);
    }

    /**
     * Hash of the polygons of one or more geometries. Independent of their order and of the float output settings.
     *
     * @param list<array<string, mixed>> $geometries
     */
    public static function geometryHash(array $geometries): string
    {
        $polygons = [];
        foreach ($geometries as $geometry) {
            $coordinates = $geometry['coordinates'] ?? [];
            foreach ('Polygon' === ($geometry['type'] ?? null) ? [$coordinates] : (array) $coordinates as $polygon) {
                $polygons[] = self::canonicalCoordinates($polygon);
            }
        }
        sort($polygons);

        return hash('sha256', implode("\n", $polygons));
    }

    /**
     * @param list<string> $geometries
     */
    private function storedHash(array $geometries): string
    {
        return self::geometryHash(array_map(
            /** @return array<string, mixed> */
            static fn (string $geometry): array => (array) json_decode($geometry, true, 32, \JSON_THROW_ON_ERROR),
            $geometries,
        ));
    }

    private static function canonicalCoordinates(mixed $value): string
    {
        if (\is_array($value)) {
            return '['.implode(',', array_map(self::canonicalCoordinates(...), $value)).']';
        }

        return sprintf('%.10F', \is_numeric($value) ? (float) $value : 0.0);
    }

    private function isComplete(): bool
    {
        return null !== $this->entityManager->find(DistrictLegacyTakeover::class, DistrictLegacyTakeover::SINGLE_ROW_ID);
    }

    /**
     * @param Transfer $transfer
     *
     * @return array<string, int>|LegacyDistrictTakeoverReport
     */
    private function resolveAreaIds(array $transfer): array|LegacyDistrictTakeoverReport
    {
        $names = array_column($transfer['areas'], 'name');

        /** @var list<array{id: int, name: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, name FROM dispatch_area WHERE name IN (?)',
            [$names],
            [ArrayParameterType::STRING],
        );

        if ([] === $rows) {
            return new LegacyDistrictTakeoverReport(LegacyDistrictTakeoverOutcome::NothingToTakeOver);
        }

        $ids = [];
        $problems = [];
        foreach ($rows as $row) {
            if (isset($ids[$row['name']])) {
                $problems[] = sprintf('Dispatch area "%s" exists more than once.', $row['name']);
            }
            $ids[$row['name']] = $row['id'];
        }

        foreach ($names as $name) {
            if (!isset($ids[$name])) {
                $problems[] = sprintf('Dispatch area "%s" is missing.', $name);
            }
        }

        return [] === $problems ? $ids : new LegacyDistrictTakeoverReport(LegacyDistrictTakeoverOutcome::Failed, problems: $problems);
    }

    /**
     * @return Transfer
     */
    private function readTransfer(): array
    {
        $content = is_readable($this->transferFile) ? file_get_contents($this->transferFile) : false;
        if (false === $content) {
            throw new \RuntimeException(sprintf('Cannot read the transfer file "%s".', $this->transferFile));
        }

        /** @var Transfer $transfer */
        $transfer = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);

        return $transfer;
    }
}
