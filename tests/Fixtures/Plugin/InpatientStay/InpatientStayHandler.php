<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Encounter\Contract\Assignment\AssignmentCounts;
use App\Encounter\Contract\Assignment\AssignmentKind;
use App\Encounter\Contract\Assignment\AssignmentRequest;
use App\Encounter\Contract\Assignment\EncounterAssignmentsInterface;
use App\Encounter\Contract\Import\EncounterRowError;
use App\Encounter\Contract\Import\EncounterSchemaHandlerInterface;
use App\Encounter\Contract\Import\RowError;
use App\Encounter\Contract\Import\RowMappingResult;
use App\Encounter\Contract\Import\SchemaWriteContext;
use App\Encounter\Contract\Import\SchemaWriteResult;
use Doctrine\DBAL\Connection;

/**
 * Handler of `inpatient_stay`: validates the cells, inserts new stays (a known stay fingerprint of the
 * hospital and key generation is a duplicate) and attaches each new stay to its encounter by case
 * pseudonym through the contract; unassigned stays are kept pending with their candidates.
 */
final readonly class InpatientStayHandler implements EncounterSchemaHandlerInterface
{
    private const string HMAC = '/^[0-9a-f]{64}$/';

    private const string ICD10 = '/^[A-Z]\d{2}(\.\d{1,2})?$/';

    public function __construct(
        private Connection $connection,
        private EncounterAssignmentsInterface $assignments,
    ) {
    }

    #[\Override]
    public function schema(): string
    {
        return InpatientStayDimension::SCHEMA;
    }

    #[\Override]
    public function createsEncounters(): bool
    {
        return false;
    }

    #[\Override]
    public function mapRow(int $line, array $fields, array $cells): RowMappingResult
    {
        if (\count($cells) !== \count($fields)) {
            return RowMappingResult::rejected([new RowError('*', EncounterRowError::ColumnCount)]);
        }

        $values = array_combine(array_map(static fn (\App\Encounter\Contract\Schema\EncounterField $field): string => $field->name, $fields), $cells);
        $errors = [];
        foreach (['stay_fingerprint', 'case_key'] as $field) {
            if ('' === $values[$field]) {
                $errors[] = new RowError($field, EncounterRowError::Required);
            } elseif (1 !== preg_match(self::HMAC, $values[$field])) {
                $errors[] = new RowError($field, EncounterRowError::InvalidFormat);
            }
        }

        $admittedAt = $this->timestamp('admitted_at', $values['admitted_at'], true, $errors);
        $dischargedAt = $this->timestamp('discharged_at', $values['discharged_at'], false, $errors);
        if ('' !== $values['discharge_diagnosis'] && 1 !== preg_match(self::ICD10, $values['discharge_diagnosis'])) {
            $errors[] = new RowError('discharge_diagnosis', EncounterRowError::InvalidCode);
        }

        if ($admittedAt instanceof \DateTimeImmutable && $dischargedAt instanceof \DateTimeImmutable && $dischargedAt < $admittedAt) {
            $errors[] = new RowError('discharged_at', EncounterRowError::Inconsistent);
        }

        if ([] !== $errors || !$admittedAt instanceof \DateTimeImmutable) {
            return RowMappingResult::rejected([] === $errors ? [new RowError('admitted_at', EncounterRowError::Required)] : $errors);
        }

        return RowMappingResult::valid(new InpatientStayRow(
            $values['stay_fingerprint'],
            $values['case_key'],
            $admittedAt,
            '' === $values['discharge_diagnosis'] ? null : $values['discharge_diagnosis'],
            $dischargedAt,
        ));
    }

    #[\Override]
    public function write(SchemaWriteContext $context, array $rows): SchemaWriteResult
    {
        $inserted = 0;
        $counts = new AssignmentCounts();
        foreach ($rows as $row) {
            if (!$row instanceof InpatientStayRow) {
                throw new \LogicException(sprintf('The inpatient_stay handler got a row of %s.', $row::class));
            }

            $request = new AssignmentRequest($context->hospitalId, $context->keyGeneration, caseKey: $row->caseKey, at: $row->admittedAt);
            $result = $this->assignments->find($request);
            $id = $this->connection->fetchOne(
                'INSERT INTO test_inpatient_stay (hospital_id, key_generation, import_id, encounter_id, stay_fingerprint, case_key, admitted_at, discharge_diagnosis, discharged_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (hospital_id, key_generation, stay_fingerprint) DO NOTHING RETURNING id',
                [
                    $context->hospitalId, $context->keyGeneration, $context->importId,
                    AssignmentKind::Assigned === $result->kind ? $result->encounterId : null,
                    $row->fingerprint, $row->caseKey, $row->admittedAt->format('Y-m-d H:i:s'),
                    $row->dischargeDiagnosis, $row->dischargedAt?->format('Y-m-d H:i:s'),
                ],
            );
            if (false === $id) {
                continue;
            }

            ++$inserted;
            $counts = $counts->with($result);
            $this->assignments->recordPending($context, InpatientStayDimension::SCHEMA, (string) $id, $request, $result);
        }

        return new SchemaWriteResult($inserted, [], $counts);
    }

    #[\Override]
    public function removeForImport(int $importId): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM test_inpatient_stay WHERE import_id = ?', [$importId]);
    }

    #[\Override]
    public function attach(string $recordId, int $encounterId): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE test_inpatient_stay SET encounter_id = ? WHERE id = ? AND encounter_id IS NULL',
            [$encounterId, (int) $recordId],
        );
    }

    /**
     * @param list<RowError> $errors
     */
    private function timestamp(string $field, string $value, bool $required, array &$errors): ?\DateTimeImmutable
    {
        if ('' === $value) {
            if ($required) {
                $errors[] = new RowError($field, EncounterRowError::Required);
            }

            return null;
        }

        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('Europe/Berlin'));
        if (false === $at || $at->format('Y-m-d\TH:i') !== $value) {
            $errors[] = new RowError($field, EncounterRowError::InvalidFormat);

            return null;
        }

        return $at;
    }
}
