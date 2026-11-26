<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Application\Export;

use App\Research\Application\Export\PlanInspector;
use App\Research\Application\Export\QueryViolation;
use App\Research\Domain\Enum\QueryKind;
use PHPUnit\Framework\TestCase;

/**
 * The allowlist check of export queries with small, hand-made EXPLAIN plans (ADR 045).
 */
final class PlanInspectorTest extends TestCase
{
    public function testAnAggregatedQueryOnAllowedTablesPasses(): void
    {
        $plan = $this->aggregate(['h.location', 'count(*)', 'array_agg(DISTINCT a.hospital_id)'], filter: "(a.arrival_at >= '2024-01-01')");

        self::assertSame([], PlanInspector::inspectPlan($plan, ['location', 'n', '_hospital_ids'], QueryKind::Aggregated));
    }

    public function testABlockedColumnIsRefusedEvenInAFilter(): void
    {
        $plan = $this->aggregate(['h.location', 'count(*)', 'array_agg(DISTINCT a.hospital_id)'], filter: '(a.notes IS NULL)');

        self::assertSame([['column', ['column' => 'allocation.notes']]], $this->codes(PlanInspector::inspectPlan($plan, ['location', 'n', '_hospital_ids'], QueryKind::Aggregated)));
    }

    public function testATableOutsideTheAllowlistIsRefused(): void
    {
        $plan = ['Node Type' => 'Seq Scan', 'Relation Name' => 'user', 'Schema' => 'public', 'Alias' => 'u', 'Output' => ['u.username']];

        self::assertSame(
            [['table', ['table' => 'user']], ['reserved_missing', ['column' => '_hospital_ids']]],
            $this->codes(PlanInspector::inspectPlan($plan, ['username'], QueryKind::Aggregated)),
        );
    }

    public function testAHospitalIdMayNotBeReturnedAsSuch(): void
    {
        $plan = $this->aggregate(['a.hospital_id', 'count(*)', 'array_agg(DISTINCT a.hospital_id)']);

        self::assertSame([['internal_output', ['column' => 'hospital_id']]], $this->codes(PlanInspector::inspectPlan($plan, ['hospital_id', 'n', '_hospital_ids'], QueryKind::Aggregated)));
    }

    public function testASingleCaseQueryReturnsTheHospitalOnlyInItsReservedColumn(): void
    {
        $plan = ['Node Type' => 'Seq Scan', 'Relation Name' => 'allocation', 'Schema' => 'public', 'Alias' => 'a', 'Output' => ['a.age', 'a.hospital_id']];

        self::assertSame([], PlanInspector::inspectPlan($plan, ['age', '_hospital_id'], QueryKind::SingleCases));
        self::assertSame([['reserved_missing', ['column' => '_hospital_id']]], $this->codes(PlanInspector::inspectPlan(['Node Type' => 'Result', 'Output' => ['1']], ['one'], QueryKind::SingleCases)));
    }

    public function testUserReferencesAreBlockedInEveryTable(): void
    {
        $plan = ['Node Type' => 'Seq Scan', 'Relation Name' => 'indication_raw', 'Schema' => 'public', 'Alias' => 'i', 'Output' => ['i.code', 'i.reviewed_by_id']];

        self::assertContains(['column', ['column' => 'indication_raw.reviewed_by_id']], $this->codes(PlanInspector::inspectPlan($plan, ['code', 'reviewer', '_hospital_ids'], QueryKind::Aggregated)));
    }

    public function testWithASingleTableBareColumnNamesCount(): void
    {
        // Postgres omits the alias when the query reads only one table.
        $plan = [
            'Node Type' => 'Aggregate',
            'Output' => ['hospital_id', 'count(*)', 'array_agg(DISTINCT hospital_id)'],
            'Plans' => [['Node Type' => 'Seq Scan', 'Relation Name' => 'allocation', 'Schema' => 'public', 'Alias' => 'a', 'Output' => ['hospital_id', 'notes'], 'Filter' => "(notes <> 'case_id_hash'::text)"]],
        ];

        self::assertSame(
            [['column', ['column' => 'allocation.notes']], ['internal_output', ['column' => 'hospital_id']]],
            $this->codes(PlanInspector::inspectPlan($plan, ['hospital_id', 'n', '_hospital_ids'], QueryKind::Aggregated)),
            'the literal "case_id_hash" is data, not a reference',
        );
    }

    public function testTheTextIsOneSelectWithoutDeniedFunctions(): void
    {
        self::assertSame([], PlanInspector::inspectText("WITH m AS (SELECT 1) SELECT * FROM m;\n"));
        self::assertSame([['not_select', []]], $this->codes(PlanInspector::inspectText('DELETE FROM allocation')));
        self::assertSame([['several_statements', []]], $this->codes(PlanInspector::inspectText('SELECT 1; SELECT 2')));
        self::assertSame(
            [['denied_function', ['function' => 'query_to_xml']], ['denied_function', ['function' => 'pg_read_file']]],
            $this->codes(PlanInspector::inspectText("SELECT query_to_xml('select * from \"user\"', true, true, ''), pg_read_file('x')")),
        );
    }

    /**
     * An aggregate over a join of allocation (a) and hospital (h), like Postgres prints it.
     *
     * @param list<string> $output
     *
     * @return array<string, mixed>
     */
    private function aggregate(array $output, string $filter = ''): array
    {
        return [
            'Node Type' => 'Aggregate',
            'Output' => $output,
            'Group Key' => ['h.location'],
            'Plans' => [[
                'Node Type' => 'Hash Join',
                'Output' => ['h.location', 'a.hospital_id'],
                'Hash Cond' => '(a.hospital_id = h.id)',
                'Plans' => [
                    ['Node Type' => 'Seq Scan', 'Relation Name' => 'allocation', 'Schema' => 'public', 'Alias' => 'a', 'Output' => ['a.hospital_id'], 'Filter' => $filter],
                    ['Node Type' => 'Seq Scan', 'Relation Name' => 'hospital', 'Schema' => 'public', 'Alias' => 'h', 'Output' => ['h.location', 'h.id']],
                ],
            ]],
        ];
    }

    /**
     * @param list<QueryViolation> $violations
     *
     * @return list<array{string, array<string, string>}>
     */
    private function codes(array $violations): array
    {
        return array_map(static fn (QueryViolation $violation): array => [$violation->code, $violation->parameters], $violations);
    }

    public function testDeniedFunctionsAreFoundBehindCommentsAndQuotes(): void
    {
        foreach ([
            "SELECT query_to_xml/**/('select 1', true, true, '') AS x",
            "SELECT \"query_to_xml\"('select 1', true, true, '') AS x",
            "SELECT pg_catalog.\"pg_read_file\"('x') AS x",
            "SELECT set_config -- comment\n ('statement_timeout', '0', true) AS x",
            "SELECT query_to_xml/* a /* nested */ comment */('select 1', true, true, '') AS x",
        ] as $sql) {
            self::assertContains('denied_function', array_map(static fn (QueryViolation $violation): string => $violation->code, PlanInspector::inspectText($sql)), $sql);
        }
    }

    public function testUnicodeEscapesAreRefused(): void
    {
        self::assertSame(
            ['unicode_escape'],
            array_map(static fn (QueryViolation $violation): string => $violation->code, PlanInspector::inspectText('SELECT U&"\0070g_sleep"(1) AS x')),
        );
    }

    public function testNamesInsideStringLiteralsAreNotCalls(): void
    {
        self::assertSame([], PlanInspector::inspectText("SELECT 'pg_read_file(' AS a, \$tag\$set_config(\$tag\$ AS b, E'it\\'s query_to_xml(' AS c"));
    }

    public function testTheLexerKeepsEscapedQuotesInsideLiteralsAndIdentifiers(): void
    {
        self::assertSame(['SELECT \'\' AS a,  b"c  AS d', false], PlanInspector::code('SELECT \'it\'\'s pg_read_file(\' AS a, "b""c" AS d'));
        self::assertSame(['SELECT  ', false], PlanInspector::code('SELECT /* never closed pg_read_file('));
        self::assertSame(["SELECT ''", false], PlanInspector::code('SELECT $x$ never closed pg_read_file('));
    }
}
