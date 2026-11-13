<?php

declare(strict_types=1);

namespace App\DataFixtures\Encounter\Infrastructure;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Encounter\Application\GeneratedEncounters;
use App\DataFixtures\Encounter\Application\PersistedVisit;
use App\DataFixtures\Encounter\Application\SyntheticEncounterKey;
use App\Encounter\Application\Dimension\EdVisitDimension;
use App\Encounter\Application\Import\EdVisitRow;
use App\Encounter\Application\Import\EdVisitRowMapper;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Encounter\Infrastructure\Repository\EncounterRepository;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes generated visits straight into the database for dev fixtures, the demo and DB tests (#1052).
 *
 * One completed import of type ENCOUNTER per call, marked `synthetic/encounters/seed-<seed>/<hospital
 * public id>/<name>.csv` (no file exists; the dataset engine treats the path as neither real nor
 * generated allocation data). Rows go through the import's row mapper and
 * `EncounterRepository::insertIfNew()`, so they are stored exactly as an upload of the same file
 * would store them. The visits carry their final state: a late assessment is already documented.
 *
 * The hospital's key state gets the check value of the synthetic key (key generation 1 unless the
 * hospital has another one); a hospital whose key state holds a different check value, or whose key
 * change is in progress, is refused, so real encounter data is never mixed with synthetic visits.
 */
final readonly class SyntheticEncounterWriter
{
    public const string PATH_PREFIX = 'synthetic/encounters/';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EdVisitRowMapper $rowMapper,
        private EncounterRepository $encounters,
        #[Autowire(param: 'app.encounter.data_prep.version')]
        private string $toolVersion,
    ) {
    }

    public static function path(int $seed, string $hospital, string $name): string
    {
        return sprintf('%sseed-%d/%s/%s.csv', self::PATH_PREFIX, $seed, $hospital, $name);
    }

    /**
     * @param string $name for example the month `2026-10`; part of the import name and marker path
     *
     * @return list<PersistedVisit> in the order of the generated visits
     */
    public function write(Hospital $hospital, int $seed, string $name, GeneratedEncounters $generated, User $createdBy, \DateTimeImmutable $uploadedAt): array
    {
        $hospitalId = (int) $hospital->getId();
        $publicId = (string) $hospital->getPublicId();
        $key = SyntheticEncounterKey::forHospital($seed, $publicId);
        $generation = $this->keyGeneration($hospitalId, $key, $uploadedAt);

        $import = new Import()
            ->setName(sprintf('Synthetic encounters %s', $name))
            ->setCreatedAt($uploadedAt)
            ->setHospital($hospital)
            ->setStatus(ImportStatus::COMPLETED)
            ->setType(ImportType::ENCOUNTER)
            ->setFilePath(self::path($seed, $publicId, $name))
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(1)
            ->setRowCount(\count($generated->visits))
            ->setRowsPassed(\count($generated->visits))
            ->setRowsRejected(0)
            ->setRowsDeduplicated(0)
            ->setRunCount(1)
            ->setRunTime(1)
            ->setCreatedBy($createdBy);
        $encounterImport = new EncounterImport($import, EdVisitDimension::SCHEMA, $generation, $createdBy, $uploadedAt);
        $encounterImport->recordManifest(1, 'synthetic', '1', $this->toolVersion);
        $this->entityManager->persist($import);
        $this->entityManager->persist($encounterImport);
        $this->entityManager->flush();

        $fields = new EdVisitDimension()->fields(1);
        $persisted = [];
        $daily = [];
        $first = null;
        $last = null;
        foreach ($generated->visits as $index => $visit) {
            $cells = $key->keysOf($visit->caseNumber, $visit->cells) + $visit->cells;
            $result = $this->rowMapper->map($index + 3, $fields, array_map(static fn (\App\Encounter\Application\Dimension\EncounterField $field): string => $cells[$field->name] ?? '', $fields));
            if (!$result->row instanceof EdVisitRow) {
                throw new \LogicException(sprintf('Synthetic visit %d is not a valid ed_visit row: %s.', $index, implode(', ', array_map(static fn (\App\Encounter\Application\Import\RowError $error): string => $error->describe(), $result->errors)) ?: 'skipped'));
            }

            $encounter = $result->row->toEncounter($hospital, $import, $generation);
            $encounterId = $this->encounters->insertIfNewReturningId($encounter);
            if (null === $encounterId) {
                throw new \LogicException(sprintf('Synthetic visit %d already exists for hospital %s; the visits of a seed are written only once.', $index, $publicId));
            }

            $persisted[] = new PersistedVisit($encounterId, $visit);
            $anchor = $encounter->getAnchorAt();
            $first = !$first instanceof \DateTimeImmutable || $anchor < $first ? $anchor : $first;
            $last = !$last instanceof \DateTimeImmutable || $anchor > $last ? $anchor : $last;
            $daily[$anchor->format('Y-m-d')] = ($daily[$anchor->format('Y-m-d')] ?? 0) + 1;
        }

        ksort($daily);
        $import->applyDataCoverage([] === $persisted ? ImportDataCoverage::empty() : new ImportDataCoverage(\count($persisted), $first, $last, $daily));
        $encounterImport->recordCounts(\count($persisted), 0, 0, 0, 0);
        $this->entityManager->flush();

        return $persisted;
    }

    /**
     * Registers the synthetic key's check value as the hospital's active one and returns the key generation.
     */
    private function keyGeneration(int $hospitalId, SyntheticEncounterKey $key, \DateTimeImmutable $now): int
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'INSERT INTO encounter_key_state (hospital_id, created_at) VALUES (?, ?) ON CONFLICT (hospital_id) DO NOTHING',
            [$hospitalId, $now],
            [Types::INTEGER, Types::DATETIME_IMMUTABLE],
        );
        $connection->executeStatement(
            'UPDATE encounter_key_state SET active_check_value = ? WHERE hospital_id = ? AND active_check_value IS NULL',
            [$key->checkValue(), $hospitalId],
            [Types::STRING, Types::INTEGER],
        );

        /** @var array{key_generation: int|string, active_check_value: ?string, key_change_in_progress: bool|string} $state */
        $state = $connection->fetchAssociative(
            'SELECT key_generation, active_check_value, key_change_in_progress FROM encounter_key_state WHERE hospital_id = ?',
            [$hospitalId],
            [Types::INTEGER],
        );
        if ($state['active_check_value'] !== $key->checkValue()) {
            throw new \RuntimeException(sprintf('Hospital %d has encounter data under another key; synthetic visits are not added.', $hospitalId));
        }
        if (true === $state['key_change_in_progress'] || 't' === $state['key_change_in_progress']) {
            throw new \RuntimeException(sprintf('Hospital %d is changing its encounter key; synthetic visits are not added.', $hospitalId));
        }

        return (int) $state['key_generation'];
    }
}
