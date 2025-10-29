<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:rebuild-aggregates',
    description: 'Recalculate pre-aggregated statistics for a given import_id'
)]
final class RebuildAggregatesCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'import',
                null,
                InputOption::VALUE_REQUIRED,
                'Which import_id should be (re)aggregated?'
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $importId = $input->getOption('import');
        if (null === $importId) {
            $output->writeln('<error>--import is required</error>');

            return Command::FAILURE;
        }

        $targets = $this->collectTargetsForImport((int) $importId);

        if ([] === $targets) {
            $output->writeln('<info>No affected rows for this import_id.</info>');

            return Command::SUCCESS;
        }

        foreach ($targets as $t) {
            $scopeType = $t['scope_type'];
            $scopeId = $t['scope_id'];
            $periodGran = $t['period_gran'];
            $periodKey = $t['period_key'];

            $output->writeln(sprintf(
                '<comment>Recomputing %s/%s %s %s</comment>',
                $scopeType,
                $scopeId,
                $periodGran,
                $periodKey
            ));

            $this->recomputeCounts($scopeType, $scopeId, $periodGran, $periodKey);
            $this->recomputeHourly($scopeType, $scopeId, $periodGran, $periodKey);
        }

        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{
     *   scope_type: string,
     *   scope_id: string,
     *   period_gran: 'day'|'week'|'quarter'|'year',
     *   period_key: string
     * }>
     */
    private function collectTargetsForImport(int $importId): array
    {
        $sql = <<<SQL
WITH new_rows AS (
    SELECT *
    FROM allocation
    WHERE import_id = :importId
),
targets AS (
    SELECT 'hospital'::text AS scope_type, new_rows.hospital_id::text AS scope_id, 'day'::text AS period_gran,     period_day(new_rows.arrival_at)     AS period_key FROM new_rows
    UNION SELECT 'hospital', new_rows.hospital_id::text, 'week',    period_week(new_rows.arrival_at)    FROM new_rows
    UNION SELECT 'hospital', new_rows.hospital_id::text, 'quarter', period_quarter(new_rows.arrival_at) FROM new_rows
    UNION SELECT 'hospital', new_rows.hospital_id::text, 'year',    period_year(new_rows.arrival_at)    FROM new_rows

    UNION SELECT 'dispatch_area', new_rows.dispatch_area_id::text, 'day',     period_day(new_rows.arrival_at)     FROM new_rows
    UNION SELECT 'dispatch_area', new_rows.dispatch_area_id::text, 'week',    period_week(new_rows.arrival_at)    FROM new_rows
    UNION SELECT 'dispatch_area', new_rows.dispatch_area_id::text, 'quarter', period_quarter(new_rows.arrival_at) FROM new_rows
    UNION SELECT 'dispatch_area', new_rows.dispatch_area_id::text, 'year',    period_year(new_rows.arrival_at)    FROM new_rows

    UNION SELECT 'state', new_rows.state_id::text, 'day',     period_day(new_rows.arrival_at)     FROM new_rows
    UNION SELECT 'state', new_rows.state_id::text, 'week',    period_week(new_rows.arrival_at)    FROM new_rows
    UNION SELECT 'state', new_rows.state_id::text, 'quarter', period_quarter(new_rows.arrival_at) FROM new_rows
    UNION SELECT 'state', new_rows.state_id::text, 'year',    period_year(new_rows.arrival_at)    FROM new_rows

    UNION SELECT 'public', 'all', 'day',     period_day(new_rows.arrival_at)     FROM new_rows
    UNION SELECT 'public', 'all', 'week',    period_week(new_rows.arrival_at)    FROM new_rows
    UNION SELECT 'public', 'all', 'quarter', period_quarter(new_rows.arrival_at) FROM new_rows
    UNION SELECT 'public', 'all', 'year',    period_year(new_rows.arrival_at)    FROM new_rows
)
SELECT DISTINCT scope_type, scope_id, period_gran, period_key::text
FROM targets
SQL;

        /** @var list<array{scope_type:mixed, scope_id:mixed, period_gran:mixed, period_key:mixed}> $rows */
        $rows = $this->db->fetchAllAssociative($sql, ['importId' => $importId]);

        $allowed = ['day', 'week', 'quarter', 'year'];

        $out = [];
        foreach ($rows as $r) {
            if (!\is_string($r['scope_type']) || !\is_string($r['scope_id']) || !\is_string($r['period_key']) || !\is_string($r['period_gran'])) {
                continue;
            }
            if (!\in_array($r['period_gran'], $allowed, true)) {
                continue;
            }

            $gran = $r['period_gran'];

            $out[] = [
                'scope_type' => $r['scope_type'],
                'scope_id' => $r['scope_id'],
                'period_gran' => $gran,
                'period_key' => $r['period_key'],
            ];
        }

        /* @var list<array{scope_type:string, scope_id:string, period_gran:'day'|'week'|'quarter'|'year', period_key:string}> $out */
        return $out;
    }

    private function recomputeCounts(string $scopeType, string $scopeId, string $gran, string $periodKey): void
    {
        $sw = $this->buildScopeWhere($scopeType, $scopeId);
        $pw = $this->buildPeriodExpr($gran, $periodKey);

        $params = array_merge($sw['params'], $pw['params']);

        $sql = <<<SQL
WITH relevant AS (
    SELECT *
    FROM allocation
    WHERE {$sw['sql']}
      AND {$pw['expr']}
)
SELECT
    COUNT(*) AS total,

    COUNT(*) FILTER (WHERE gender = 'M') AS gender_m,
    COUNT(*) FILTER (WHERE gender = 'F') AS gender_w,
    COUNT(*) FILTER (WHERE gender = 'X') AS gender_d,
    0 AS gender_u,

    COUNT(*) FILTER (WHERE urgency = 1) AS urg_1,
    COUNT(*) FILTER (WHERE urgency = 2) AS urg_2,
    COUNT(*) FILTER (WHERE urgency = 3) AS urg_3,

    COUNT(*) FILTER (WHERE requires_cathlab)  AS cathlab_required,
    COUNT(*) FILTER (WHERE requires_resus)    AS resus_required,

    COUNT(*) FILTER (WHERE is_cpr)            AS is_cpr,
    COUNT(*) FILTER (WHERE is_ventilated)     AS is_ventilated,
    COUNT(*) FILTER (WHERE is_shock)          AS is_shock,
    COUNT(*) FILTER (WHERE is_pregnant)       AS is_pregnant,
    COUNT(*) FILTER (WHERE is_with_physician) AS with_physician,

    COUNT(*) FILTER (WHERE infection_id IS NOT NULL) AS infectious
FROM relevant;
SQL;

        /** @var array<string,int|string|null>|false $row */
        $row = $this->db->fetchAssociative($sql, $params);
        if (false === $row) {
            $row = [
                'total' => 0,
                'gender_m' => 0, 'gender_w' => 0, 'gender_d' => 0, 'gender_u' => 0,
                'urg_1' => 0, 'urg_2' => 0, 'urg_3' => 0,
                'cathlab_required' => 0, 'resus_required' => 0,
                'is_cpr' => 0, 'is_ventilated' => 0, 'is_shock' => 0, 'is_pregnant' => 0, 'with_physician' => 0,
                'infectious' => 0,
            ];
        }

        $upsert = <<<SQL
INSERT INTO agg_allocations_counts (
    scope_type, scope_id, period_gran, period_key,
    total,
    gender_m, gender_w, gender_d, gender_u,
    urg_1, urg_2, urg_3,
    cathlab_required, resus_required,
    is_cpr, is_ventilated, is_shock, is_pregnant, with_physician,
    infectious
)
VALUES (
    :scope_type, :scope_id, :period_gran, :period_key,
    :total,
    :gender_m, :gender_w, :gender_d, :gender_u,
    :urg_1, :urg_2, :urg_3,
    :cathlab_required, :resus_required,
    :is_cpr, :is_ventilated, :is_shock, :is_pregnant, :with_physician,
    :infectious
)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
    total            = EXCLUDED.total,
    gender_m         = EXCLUDED.gender_m,
    gender_w         = EXCLUDED.gender_w,
    gender_d         = EXCLUDED.gender_d,
    gender_u         = EXCLUDED.gender_u,
    urg_1            = EXCLUDED.urg_1,
    urg_2            = EXCLUDED.urg_2,
    urg_3            = EXCLUDED.urg_3,
    cathlab_required = EXCLUDED.cathlab_required,
    resus_required   = EXCLUDED.resus_required,
    is_cpr           = EXCLUDED.is_cpr,
    is_ventilated    = EXCLUDED.is_ventilated,
    is_shock         = EXCLUDED.is_shock,
    is_pregnant      = EXCLUDED.is_pregnant,
    with_physician   = EXCLUDED.with_physician,
    infectious       = EXCLUDED.infectious,
    computed_at      = now();
SQL;

        $upParams = array_merge($row, [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'period_gran' => $gran,
            'period_key' => $periodKey,
        ]);

        $this->db->executeStatement($upsert, $upParams);
    }

    private function recomputeHourly(string $scopeType, string $scopeId, string $gran, string $periodKey): void
    {
        $sw = $this->buildScopeWhere($scopeType, $scopeId);
        $pw = $this->buildPeriodExpr($gran, $periodKey);

        $params = array_merge($sw['params'], $pw['params']);

        $sql = <<<SQL
WITH relevant AS (
    SELECT
        EXTRACT(HOUR FROM (arrival_at AT TIME ZONE 'Europe/Berlin'))::int AS h
    FROM allocation
    WHERE {$sw['sql']}
      AND {$pw['expr']}
),
hist AS (
    SELECT ARRAY(
        SELECT COALESCE(SUM((h = i)::int),0)
        FROM generate_series(0,23) AS i
    ) AS hours_count
    FROM relevant
)
SELECT hours_count FROM hist;
SQL;

        /** @var array{hours_count:string}|false $row */
        $row = $this->db->fetchAssociative($sql, $params);
        if (false === $row) {
            $row = [
                'hours_count' => '{'.implode(',', array_fill(0, 24, 0)).'}',
            ];
        }

        $upsert = <<<SQL
INSERT INTO agg_allocations_hourly (
    scope_type, scope_id, period_gran, period_key,
    hours_count
)
VALUES (
    :scope_type, :scope_id, :period_gran, :period_key,
    :hours_count
)
ON CONFLICT (scope_type, scope_id, period_gran, period_key)
DO UPDATE SET
    hours_count = EXCLUDED.hours_count,
    computed_at = now();
SQL;

        $upParams = [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'period_gran' => $gran,
            'period_key' => $periodKey,
            'hours_count' => $row['hours_count'],
        ];

        $this->db->executeStatement($upsert, $upParams);
    }

    /**
     * @return array{
     *   sql: string,
     *   params: array<string, string|int|float|bool|null>
     * }
     */
    private function buildScopeWhere(string $scopeType, string $scopeId): array
    {
        return match ($scopeType) {
            'public' => ['sql' => 'TRUE',                       'params' => []],
            'hospital' => ['sql' => 'hospital_id = :scope_id',    'params' => ['scope_id' => (int) $scopeId]],
            'dispatch_area' => ['sql' => 'dispatch_area_id = :scope_id', 'params' => ['scope_id' => (int) $scopeId]],
            'state' => ['sql' => 'state_id = :scope_id',       'params' => ['scope_id' => (int) $scopeId]],
            default => throw new \RuntimeException('Unknown scopeType '.$scopeType),
        };
    }

    /**
     * @return array{
     *   expr: string,
     *   params: array<string, string|int|float|bool|null>
     * }
     */
    private function buildPeriodExpr(string $gran, string $periodKey): array
    {
        return match ($gran) {
            'day' => ['expr' => 'period_day(arrival_at)      = :period_key::date', 'params' => ['period_key' => $periodKey]],
            'week' => ['expr' => 'period_week(arrival_at)     = :period_key::date', 'params' => ['period_key' => $periodKey]],
            'quarter' => ['expr' => 'period_quarter(arrival_at)  = :period_key::date', 'params' => ['period_key' => $periodKey]],
            'year' => ['expr' => 'period_year(arrival_at)     = :period_key::date', 'params' => ['period_key' => $periodKey]],
            default => throw new \RuntimeException('Unknown gran '.$gran),
        };
    }
}
