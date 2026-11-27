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
 * - In an aggregated query the reserved column is `array_agg(DISTINCT <alias>.hospital_id)` over `allocation` in the
 *   top grouping, and no other result column of that grouping uses a hospital ID (#1692). The runner counts the
 *   contributing hospitals of every group from this column, so it must not be a constant, come from another grouping
 *   or list hospitals a result column does not cover.
 */
final class PlanInspector
{
    private const string REFERENCE = '/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/';

    /** A bare identifier that is not a function call. */
    private const string BARE = '/(?<![.\w])([a-z_][a-z0-9_]*)\b(?!\s*[.(])/';

    /** Nodes above the top grouping that only pass its rows on, with every expression in parentheses. */
    private const array PASS_THROUGH_NODES = ['Limit', 'Sort', 'Incremental Sort'];

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

        // Function names are matched on the code alone: without comments and string literals, with quoted identifiers
        // unquoted, so `name/**/(` or `"name"(` cannot hide a call (#1690).
        [$code, $unicodeEscapes] = self::code($sql);
        if ($unicodeEscapes) {
            $violations[] = new QueryViolation(QueryViolation::UNICODE_ESCAPE);
        }

        if (preg_match_all(ExportAllowlist::DENIED_FUNCTIONS, $code, $matches) > 0) {
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
        } elseif (QueryKind::Aggregated === $kind) {
            $violations = [...$violations, ...self::inspectGrouping($plan, $columns, $aliases, $single, $kind->reservedColumn())];
        }

        return self::unique($violations);
    }

    /**
     * The reserved column and the other result columns of the top grouping (#1692).
     *
     * @param array<array-key, mixed>     $plan
     * @param list<string>                $columns
     * @param array<string, list<string>> $aliases
     *
     * @return list<QueryViolation>
     */
    private static function inspectGrouping(array $plan, array $columns, array $aliases, ?string $single, string $reserved): array
    {
        $index = array_search($reserved, $columns, true);
        $node = $plan;
        while (\in_array($node['Node Type'] ?? null, self::PASS_THROUGH_NODES, true)) {
            $child = \is_array($node['Plans'] ?? null) ? ($node['Plans'][0] ?? null) : null;
            if (!\is_array($child) || !self::passesOn($node, $child, \count($columns))) {
                return [new QueryViolation(QueryViolation::RESERVED_NOT_DERIVED, ['column' => $reserved])];
            }

            $node = $child;
        }

        $outputs = 'Aggregate' === ($node['Node Type'] ?? null) ? self::outputs($node) : [];
        $expression = \is_int($index) ? ($outputs[$index] ?? null) : null;
        if (null === $expression || !self::isHospitalList($expression, $aliases, $single)) {
            return [new QueryViolation(QueryViolation::RESERVED_NOT_DERIVED, ['column' => $reserved])];
        }

        // A result column computed from single hospitals (FILTER, CASE) would not be covered by the listed hospitals.
        $violations = [];
        foreach ($columns as $position => $name) {
            $output = $outputs[$position] ?? null;
            if ($position === $index || null === $output || 1 === preg_match('/^(?:[a-z_][a-z0-9_]*\.)?hospital_id$/', $output)) {
                continue; // A bare hospital ID is reported as internal output already.
            }

            if (self::usesHospitalId($output, $aliases, $single)) {
                $violations[] = new QueryViolation(QueryViolation::HOSPITAL_DEPENDENT_OUTPUT, ['column' => $name]);
            }
        }

        return $violations;
    }

    /**
     * Whether a pass-through node returns its child's result columns unchanged (Postgres prints them in parentheses).
     *
     * @param array<array-key, mixed> $node
     * @param array<array-key, mixed> $child
     */
    private static function passesOn(array $node, array $child, int $count): bool
    {
        $outputs = self::outputs($node);
        $childOutputs = self::outputs($child);
        for ($position = 0; $position < $count; ++$position) {
            $output = $outputs[$position] ?? null;
            $childOutput = $childOutputs[$position] ?? null;
            if (null === $output || null === $childOutput || ($output !== $childOutput && $output !== '('.$childOutput.')')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The output expressions of a plan node; anything that is not a string becomes an empty string.
     *
     * @param array<array-key, mixed> $node
     *
     * @return list<string>
     */
    private static function outputs(array $node): array
    {
        $outputs = \is_array($node['Output'] ?? null) ? $node['Output'] : [];

        return array_values(array_map(static fn (mixed $output): string => \is_string($output) ? $output : '', $outputs));
    }

    /**
     * `array_agg(DISTINCT <alias>.hospital_id)` where the alias is a scan of `allocation`, nothing more.
     *
     * @param array<string, list<string>> $aliases
     */
    private static function isHospitalList(string $expression, array $aliases, ?string $single): bool
    {
        if (1 !== preg_match('/^array_agg\(DISTINCT (?:([a-z_][a-z0-9_]*)\.)?hospital_id\)$/', $expression, $match)) {
            return false;
        }

        $alias = $match[1] ?? '';

        return '' === $alias ? 'allocation' === $single : ['allocation'] === ($aliases[$alias] ?? []);
    }

    /**
     * Whether an expression reads a hospital ID: `allocation.hospital_id` or `hospital.id`.
     *
     * @param array<string, list<string>> $aliases
     */
    private static function usesHospitalId(string $expression, array $aliases, ?string $single): bool
    {
        // String literals are data, not references.
        $expression = (string) preg_replace("/'(?:[^']|'')*'/", "''", $expression);
        $bareColumn = match ($single) {
            'allocation' => 'hospital_id',
            'hospital' => 'id',
            default => null,
        };
        if (null !== $bareColumn && 1 === preg_match('/(?<![.\w])'.$bareColumn.'\b(?!\s*[.(])/', $expression)) {
            return true;
        }

        if (preg_match_all(self::REFERENCE, $expression, $matches, \PREG_SET_ORDER) > 0) {
            foreach ($matches as [, $alias, $column]) {
                foreach ($aliases[$alias] ?? [] as $table) {
                    if (('allocation' === $table && 'hospital_id' === $column) || ('hospital' === $table && 'id' === $column)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Without a trailing semicolon and surrounding space, as it is stored and run. */
    public static function normalize(string $sql): string
    {
        return rtrim(trim($sql), "; \t\n\r");
    }

    /**
     * The SQL as PostgreSQL reads its names: comments become a space, string literals (also E'…' and dollar-quoted)
     * become '', and quoted identifiers lose their quotes. The flag reports Unicode escapes (U&"…", U&'…'), which can
     * spell any name and are refused.
     *
     * @return array{0: string, 1: bool}
     */
    public static function code(string $sql): array
    {
        $code = '';
        $unicode = false;
        $length = \strlen($sql);
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ('-' === $char && '-' === $next) {
                $end = strpos($sql, "\n", $i);
                $i = false === $end ? $length : $end;
                $code .= ' ';
                continue;
            }

            if ('/' === $char && '*' === $next) {
                $depth = 1;
                $i += 2;
                while ($i < $length && $depth > 0) {
                    if ('/' === $sql[$i] && '*' === ($sql[$i + 1] ?? '')) {
                        ++$depth;
                        $i += 2;
                    } elseif ('*' === $sql[$i] && '/' === ($sql[$i + 1] ?? '')) {
                        --$depth;
                        $i += 2;
                    } else {
                        ++$i;
                    }
                }
                $code .= ' ';
                continue;
            }

            if (('u' === $char || 'U' === $char) && '&' === $next && \in_array($sql[$i + 2] ?? '', ['"', "'"], true)) {
                $unicode = true;
                $i += 2;
                continue;
            }

            if ("'" === $char) {
                $backslash = $i > 0 && \in_array($sql[$i - 1], ['e', 'E'], true);
                ++$i;
                while ($i < $length) {
                    if ($backslash && '\\' === $sql[$i]) {
                        $i += 2;
                    } elseif ("'" === $sql[$i] && "'" === ($sql[$i + 1] ?? '')) {
                        $i += 2;
                    } elseif ("'" === $sql[$i]) {
                        ++$i;
                        break;
                    } else {
                        ++$i;
                    }
                }
                $code .= "''";
                continue;
            }

            if ('$' === $char && 1 === preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $tag, 0, $i)) {
                $end = strpos($sql, $tag[0], $i + \strlen($tag[0]));
                $i = false === $end ? $length : $end + \strlen($tag[0]);
                $code .= "''";
                continue;
            }

            if ('"' === $char) {
                ++$i;
                $identifier = '';
                while ($i < $length) {
                    if ('"' === $sql[$i] && '"' === ($sql[$i + 1] ?? '')) {
                        $identifier .= '"';
                        $i += 2;
                    } elseif ('"' === $sql[$i]) {
                        ++$i;
                        break;
                    } else {
                        $identifier .= $sql[$i];
                        ++$i;
                    }
                }
                $code .= ' '.$identifier.' ';
                continue;
            }

            $code .= $char;
            ++$i;
        }

        return [$code, $unicode];
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
