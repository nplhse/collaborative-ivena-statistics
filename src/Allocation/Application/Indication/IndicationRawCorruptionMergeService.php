<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Import\Infrastructure\Indication\IndicationKey;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class IndicationRawCorruptionMergeService
{
    private const int STUB_STRIP_MIN = 3;
    private const int STUB_STRIP_MAX = 5;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function run(bool $dryRun): IndicationRawMergeResult
    {
        $raws = $this->connection->fetchAllAssociative(
            'SELECT id, code, name, hash, review_status, target_id, normalized_id FROM indication_raw ORDER BY id ASC',
        );

        $occurrence = $this->occurrenceCounts();
        $newHashes = [];
        $rehashUpdates = [];

        foreach ($raws as $raw) {
            $id = (int) $raw['id'];
            $code = (string) $raw['code'];
            $name = (string) $raw['name'];
            $hash = IndicationKey::hashFrom($code, $name);
            $newHashes[$id] = $hash;
            if ($hash !== (string) $raw['hash']) {
                $rehashUpdates[$id] = $hash;
            }
        }

        $actions = [];
        $consumed = [];

        $byHash = [];
        foreach ($raws as $raw) {
            $id = (int) $raw['id'];
            $byHash[$newHashes[$id]][] = $raw;
        }

        foreach ($byHash as $group) {
            if (\count($group) < 2) {
                continue;
            }

            $survivor = $this->chooseSurvivor($group, $occurrence);
            $survivorId = (int) $survivor['id'];

            foreach ($group as $loser) {
                $loserId = (int) $loser['id'];
                if ($loserId === $survivorId) {
                    continue;
                }

                $consumed[$loserId] = true;
                $actions[] = $this->buildMergeAction(
                    'quote_variant',
                    $loser,
                    $survivor,
                    $occurrence[$loserId] ?? 0,
                );
            }
        }

        $byCode = [];
        foreach ($raws as $raw) {
            $id = (int) $raw['id'];
            if (isset($consumed[$id])) {
                continue;
            }
            $byCode[(int) $raw['code']][] = $raw;
        }

        $normalizedByCode = $this->normalizedByCode();

        foreach ($byCode as $codeRaws) {
            foreach ($codeRaws as $candidate) {
                $candidateId = (int) $candidate['id'];
                if (isset($consumed[$candidateId]) || !$this->isOpenStub($candidate)) {
                    continue;
                }

                $intact = $this->findIntactSibling($candidate, $codeRaws, $consumed);
                if (null !== $intact) {
                    $consumed[$candidateId] = true;
                    $actions[] = $this->buildMergeAction(
                        'stub',
                        $candidate,
                        $intact,
                        $occurrence[$candidateId] ?? 0,
                    );
                    continue;
                }

                $catalog = $this->findCatalogMatch($candidate, $normalizedByCode[(int) $candidate['code']] ?? []);
                if (null === $catalog) {
                    continue;
                }

                $actions[] = new IndicationRawMergeAction(
                    type: 'stub_restore',
                    loserId: $candidateId,
                    survivorId: null,
                    code: (int) $candidate['code'],
                    beforeName: (string) $candidate['name'],
                    afterName: $catalog['name'],
                    allocationCount: $occurrence[$candidateId] ?? 0,
                );
            }
        }

        $affectedImportIds = [];
        if (!$dryRun) {
            $this->connection->beginTransaction();
            try {
                foreach ($rehashUpdates as $id => $hash) {
                    $this->connection->executeStatement(
                        'UPDATE indication_raw SET hash = :hash WHERE id = :id',
                        ['hash' => $hash, 'id' => $id],
                        ['id' => Types::INTEGER],
                    );
                }

                foreach ($actions as $index => $action) {
                    $importIds = $this->applyAction($action, $normalizedByCode);
                    $actions[$index] = new IndicationRawMergeAction(
                        $action->type,
                        $action->loserId,
                        $action->survivorId,
                        $action->code,
                        $action->beforeName,
                        $action->afterName,
                        $action->allocationCount,
                    );
                    foreach ($importIds as $importId) {
                        $affectedImportIds[$importId] = true;
                    }
                }

                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        }

        $ids = array_keys($affectedImportIds);
        sort($ids);

        return new IndicationRawMergeResult(\count($rehashUpdates), $actions, $ids);
    }

    /**
     * @param array<string, mixed> $actionRaw
     * @param array<string, mixed> $survivor
     */
    private function buildMergeAction(string $type, array $actionRaw, array $survivor, int $allocationCount): IndicationRawMergeAction
    {
        return new IndicationRawMergeAction(
            type: $type,
            loserId: (int) $actionRaw['id'],
            survivorId: (int) $survivor['id'],
            code: (int) $actionRaw['code'],
            beforeName: (string) $actionRaw['name'],
            afterName: (string) $survivor['name'],
            allocationCount: $allocationCount,
        );
    }

    /**
     * @param array<int, list<array{id: int, name: string}>> $normalizedByCode
     *
     * @return list<int>
     */
    private function applyAction(IndicationRawMergeAction $action, array $normalizedByCode): array
    {
        if ('stub_restore' === $action->type) {
            $catalog = $this->findCatalogByName($action->afterName, $normalizedByCode[$action->code] ?? []);
            $hash = IndicationKey::hashFrom((string) $action->code, $action->afterName);
            $existingId = $this->connection->fetchOne(
                'SELECT id FROM indication_raw WHERE hash = :hash AND id <> :id',
                ['hash' => $hash, 'id' => $action->loserId],
                ['id' => Types::INTEGER],
            );
            if (false !== $existingId && null !== $existingId) {
                return $this->applyAction(new IndicationRawMergeAction(
                    type: 'stub',
                    loserId: $action->loserId,
                    survivorId: (int) $existingId,
                    code: $action->code,
                    beforeName: $action->beforeName,
                    afterName: $action->afterName,
                    allocationCount: $action->allocationCount,
                ), $normalizedByCode);
            }
            $this->connection->executeStatement(
                <<<'SQL'
UPDATE indication_raw
SET name = :name,
    hash = :hash,
    target_id = COALESCE(target_id, CAST(:targetId AS INTEGER)),
    normalized_id = COALESCE(normalized_id, CAST(:normalizedId AS INTEGER)),
    review_status = CASE
        WHEN CAST(:targetId AS INTEGER) IS NOT NULL AND review_status = 'unreviewed' THEN 'matched'
        ELSE review_status
    END
WHERE id = :id
SQL,
                [
                    'name' => $action->afterName,
                    'hash' => $hash,
                    'targetId' => $catalog['id'] ?? null,
                    'normalizedId' => $catalog['id'] ?? null,
                    'id' => $action->loserId,
                ],
                [
                    'targetId' => Types::INTEGER,
                    'normalizedId' => Types::INTEGER,
                    'id' => Types::INTEGER,
                ],
            );

            return $this->importIdsForRaw($action->loserId);
        }

        $survivorId = $action->survivorId;
        if (null === $survivorId) {
            return [];
        }

        $survivor = $this->connection->fetchAssociative(
            'SELECT id, target_id, normalized_id FROM indication_raw WHERE id = :id',
            ['id' => $survivorId],
            ['id' => Types::INTEGER],
        );
        if (false === $survivor) {
            return [];
        }

        $normalizedId = $survivor['normalized_id'] ?? $survivor['target_id'];
        $importIds = $this->importIdsForRaw($action->loserId);

        $this->connection->executeStatement(
            'UPDATE allocation SET indication_raw_id = :survivor, indication_normalized_id = COALESCE(:normalizedId, indication_normalized_id) WHERE indication_raw_id = :loser',
            ['survivor' => $survivorId, 'normalizedId' => $normalizedId, 'loser' => $action->loserId],
            ['survivor' => Types::INTEGER, 'loser' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            'UPDATE allocation SET secondary_indication_raw_id = :survivor, secondary_indication_normalized_id = COALESCE(:normalizedId, secondary_indication_normalized_id) WHERE secondary_indication_raw_id = :loser',
            ['survivor' => $survivorId, 'normalizedId' => $normalizedId, 'loser' => $action->loserId],
            ['survivor' => Types::INTEGER, 'loser' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            'UPDATE mci_case SET indication_raw_id = :survivor, indication_normalized_id = COALESCE(:normalizedId, indication_normalized_id) WHERE indication_raw_id = :loser',
            ['survivor' => $survivorId, 'normalizedId' => $normalizedId, 'loser' => $action->loserId],
            ['survivor' => Types::INTEGER, 'loser' => Types::INTEGER],
        );
        $this->connection->executeStatement(
            'DELETE FROM indication_raw WHERE id = :id',
            ['id' => $action->loserId],
            ['id' => Types::INTEGER],
        );

        return $importIds;
    }

    /**
     * @return array<int, int>
     */
    private function occurrenceCounts(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT raw_id, SUM(cnt)::int AS cnt
FROM (
    SELECT indication_raw_id AS raw_id, COUNT(*)::int AS cnt
    FROM allocation
    GROUP BY indication_raw_id
    UNION ALL
    SELECT secondary_indication_raw_id AS raw_id, COUNT(*)::int AS cnt
    FROM allocation
    WHERE secondary_indication_raw_id IS NOT NULL
    GROUP BY secondary_indication_raw_id
    UNION ALL
    SELECT indication_raw_id AS raw_id, COUNT(*)::int AS cnt
    FROM mci_case
    WHERE indication_raw_id IS NOT NULL
    GROUP BY indication_raw_id
) counts
GROUP BY raw_id
SQL
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['raw_id']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $group
     * @param array<int, int>            $occurrence
     *
     * @return array<string, mixed>
     */
    private function chooseSurvivor(array $group, array $occurrence): array
    {
        usort($group, function (array $a, array $b) use ($occurrence): int {
            $scoreA = $this->survivorScore($a, $occurrence);
            $scoreB = $this->survivorScore($b, $occurrence);
            if ($scoreA[0] !== $scoreB[0]) {
                return $scoreB[0] <=> $scoreA[0];
            }
            if ($scoreA[1] !== $scoreB[1]) {
                return $scoreB[1] <=> $scoreA[1];
            }

            return $scoreA[2] <=> $scoreB[2];
        });

        return $group[0];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, int>      $occurrence
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function survivorScore(array $raw, array $occurrence): array
    {
        $matched = IndicationRawReviewStatus::Matched->value === $raw['review_status']
            || null !== $raw['target_id'];

        return [
            $matched ? 1 : 0,
            $occurrence[(int) $raw['id']] ?? 0,
            (int) $raw['id'],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function isOpenStub(array $raw): bool
    {
        $status = (string) $raw['review_status'];

        return IndicationRawReviewStatus::Unreviewed->value === $status
            || IndicationRawReviewStatus::NeedsReview->value === $status;
    }

    /**
     * @param array<string, mixed>       $stub
     * @param list<array<string, mixed>> $siblings
     * @param array<int, true>           $consumed
     *
     * @return array<string, mixed>|null
     */
    private function findIntactSibling(array $stub, array $siblings, array $consumed): ?array
    {
        $stubName = (string) $stub['name'];
        foreach ($siblings as $sibling) {
            $siblingId = (int) $sibling['id'];
            if ($siblingId === (int) $stub['id'] || isset($consumed[$siblingId])) {
                continue;
            }
            if ($this->isStubOf($stubName, (string) $sibling['name'])) {
                return $sibling;
            }
        }

        return null;
    }

    private function isStubOf(string $stub, string $intact): bool
    {
        $intactLen = mb_strlen($intact);
        for ($n = self::STUB_STRIP_MIN; $n <= self::STUB_STRIP_MAX; ++$n) {
            if ($intactLen > $n && mb_substr($intact, $n) === $stub) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function normalizedByCode(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, code, name FROM indication_normalized');
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['code']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>               $stub
     * @param list<array{id: int, name: string}> $catalog
     *
     * @return array{id: int, name: string}|null
     */
    private function findCatalogMatch(array $stub, array $catalog): ?array
    {
        $stubName = (string) $stub['name'];
        foreach ($catalog as $entry) {
            if ($this->isStubOf($stubName, $entry['name'])) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int, name: string}> $catalog
     *
     * @return array{id: int, name: string}|null
     */
    private function findCatalogByName(string $name, array $catalog): ?array
    {
        foreach ($catalog as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function importIdsForRaw(int $rawId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT DISTINCT import_id
FROM (
    SELECT import_id FROM allocation WHERE indication_raw_id = :rawId OR secondary_indication_raw_id = :rawId
    UNION
    SELECT import_id FROM mci_case WHERE indication_raw_id = :rawId
) ids
ORDER BY import_id
SQL,
            ['rawId' => $rawId],
            ['rawId' => Types::INTEGER],
        );

        return array_map(intval(...), $rows);
    }
}
