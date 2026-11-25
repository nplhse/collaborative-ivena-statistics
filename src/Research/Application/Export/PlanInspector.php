<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

use App\Research\Domain\Enum\QueryKind;

/**
 * Checks an export query against the allowlist (ADR 045), from its text, its EXPLAIN (VERBOSE, FORMAT JSON) plan and
 * the names of its result columns. Pure, so it can be tested with small hand-made plans.
 *
 * - The text is one SELECT (or WITH) statement and calls no denied function.
 * - Every table in the plan is an allowlisted table in the public schema.
 * - No blocked column is referenced anywhere in the plan, not even in a filter or join.
 * - No result column returns an internal-only column as such (for example a hospital ID), except the reserved column
 *   of a single-case query.
 * - The reserved column of the query kind is present.
 */
final class PlanInspector
{
    private const string REFERENCE = '/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/';

    /** A bare identifier that is not a function call. */
    private const string BARE = '/(?<![.\w])([a-z_][a-z0-9_]*)\b(?!\s*[.(])/';

    /**
     * @return list<QueryViolation>
     */
    public static function inspectText(string $sql): array
    {
        $sql = self::normalize($sql);
        $violations = [];
        if (1 !== preg_match('/^\s*(select|with)\b/i', $sql)) {
            $violations[] = new QueryViolation(QueryViolation::NOT_SELECT);
        }

        if (str_contains($sql, ';')) {
            $violations[] = new QueryViolation(QueryViolation::SEVERAL_STATEMENTS);
        }

        if (preg_match_all(ExportAllowlist::DENIED_FUNCTIONS, $sql, $matches) > 0) {
            foreach (array_unique(array_map(strtolower(...), $matches[1])) as $function) {
                $violations[] = new QueryViolation(QueryViolation::DENIED_FUNCTION, ['function' => $function]);
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $plan    the "Plan" object of the EXPLAIN result
     * @param list<string>            $columns names of the result columns, in order
     *
     * @return list<QueryViolation>
     */
    public static function inspectPlan(array $plan, array $columns, QueryKind $kind): array
    {
        $aliases = [];
        $violations = [];
        self::collectRelations($plan, $aliases, $violations);

        // With a single relation Postgres prints columns without the alias, so bare names belong to that table.
        $tables = array_values(array_unique(array_merge(...array_values($aliases))));
        $single = 1 === \count($aliases) && 1 === \count($tables) ? $tables[0] : null;

        $references = [];
        self::collectReferences($plan, $aliases, $single, $references);
        foreach ($references as $reference => [$table, $column]) {
            if (ExportAllowlist::isBlocked($table, $column)) {
                $violations[] = new QueryViolation(QueryViolation::COLUMN, ['column' => $reference]);
            }
        }

        /** @var list<mixed> $outputs */
        $outputs = \is_array($plan['Output'] ?? null) ? array_values($plan['Output']) : [];
        foreach ($outputs as $index => $expression) {
            $name = $columns[$index] ?? '';
            if (!\is_string($expression)) {
                continue;
            }

            $candidates = [];
            if (1 === preg_match('/^([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)$/', $expression, $match)) {
                $candidates = array_map(static fn (string $table): array => [$table, $match[2]], $aliases[$match[1]] ?? []);
            } elseif (null !== $single && 1 === preg_match('/^([a-z_][a-z0-9_]*)$/', $expression)) {
                $candidates = [[$single, $expression]];
            }

            foreach ($candidates as [$table, $column]) {
                if (ExportAllowlist::isInternalOnly($table, $column) && (QueryKind::SingleCases !== $kind || $kind->reservedColumn() !== $name)) {
                    $violations[] = new QueryViolation(QueryViolation::INTERNAL_OUTPUT, ['column' => '' !== $name ? $name : $expression]);
                }
            }
        }

        if (!\in_array($kind->reservedColumn(), $columns, true)) {
            $violations[] = new QueryViolation(QueryViolation::RESERVED_MISSING, ['column' => $kind->reservedColumn()]);
        }

        return self::unique($violations);
    }

    /** Without a trailing semicolon and surrounding space, as it is stored and run. */
    public static function normalize(string $sql): string
    {
        return rtrim(trim($sql), "; \t\n\r");
    }

    /**
     * @param array<array-key, mixed>     $node
     * @param array<string, list<string>> $aliases
     * @param list<QueryViolation>        $violations
     */
    private static function collectRelations(array $node, array &$aliases, array &$violations): void
    {
        $relation = $node['Relation Name'] ?? null;
        if (\is_string($relation)) {
            $schema = \is_string($node['Schema'] ?? null) ? $node['Schema'] : 'public';
            if ('public' !== $schema || !\in_array($relation, ExportAllowlist::TABLES, true)) {
                $violations[] = new QueryViolation(QueryViolation::TABLE, ['table' => 'public' === $schema ? $relation : $schema.'.'.$relation]);
            }

            $alias = \is_string($node['Alias'] ?? null) ? $node['Alias'] : $relation;
            $aliases[$alias] = array_values(array_unique([...($aliases[$alias] ?? []), $relation]));
        }

        foreach (\is_array($node['Plans'] ?? null) ? $node['Plans'] : [] as $child) {
            if (\is_array($child)) {
                self::collectRelations($child, $aliases, $violations);
            }
        }
    }

    /**
     * @param array<array-key, mixed>                    $node
     * @param array<string, list<string>>                $aliases
     * @param array<string, array{0: string, 1: string}> $references
     */
    private static function collectReferences(array $node, array $aliases, ?string $single, array &$references): void
    {
        foreach ($node as $key => $value) {
            if ('Plans' === $key) {
                foreach (\is_array($value) ? $value : [] as $child) {
                    if (\is_array($child)) {
                        self::collectReferences($child, $aliases, $single, $references);
                    }
                }

                continue;
            }

            if (\in_array($key, ['Node Type', 'Relation Name', 'Alias', 'Schema', 'Strategy', 'Parent Relationship', 'Join Type', 'Scan Direction', 'Index Name', 'Sort Method', 'Partial Mode', 'Subplan Name', 'CTE Name'], true)) {
                continue;
            }

            foreach (self::strings($value) as $text) {
                // String literals are data, not references.
                $text = (string) preg_replace("/'(?:[^']|'')*'/", "''", $text);
                if (null !== $single && preg_match_all(self::BARE, $text, $bare) > 0) {
                    foreach ($bare[1] as $column) {
                        $references[$single.'.'.$column] = [$single, $column];
                    }
                }

                if (preg_match_all(self::REFERENCE, $text, $matches, \PREG_SET_ORDER) > 0) {
                    foreach ($matches as [, $alias, $column]) {
                        foreach ($aliases[$alias] ?? [] as $table) {
                            $references[$table.'.'.$column] = [$table, $column];
                        }
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $strings = [...$strings, ...self::strings($item)];
        }

        return $strings;
    }

    /**
     * @param list<QueryViolation> $violations
     *
     * @return list<QueryViolation>
     */
    private static function unique(array $violations): array
    {
        $unique = [];
        foreach ($violations as $violation) {
            $unique[$violation->code.json_encode($violation->parameters, \JSON_THROW_ON_ERROR)] = $violation;
        }

        return array_values($unique);
    }
}
