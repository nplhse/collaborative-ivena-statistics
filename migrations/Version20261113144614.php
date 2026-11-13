<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The explorer age groups now match the rest of the statistics (#1040): adults from 18, so `0_18` becomes `0_17`
 * and `19_29` becomes `18_29`. Saved explorer views store the age-group filter in `query.filters[]` as
 * `{dimensionKey: "age_group", operator, value}`; only those values are rewritten. Other filters, aggregate values
 * (`under_18`, `adults`, `over_80`) and the order of the filters stay as they are.
 *
 * `down()` maps the new keys back, including filters saved with `0_17` or `18_29` after this migration.
 */
final class Version20261113144614 extends AbstractMigration
{
    private const array UP = ['0_18' => '0_17', '19_29' => '18_29'];

    public function getDescription(): string
    {
        return 'Rewrite saved explorer view age-group filters 0_18/19_29 to 0_17/18_29 (#1040)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql($this->rewriteSql(self::UP));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->rewriteSql(array_flip(self::UP)));
    }

    /**
     * @param array<string, string> $map old value => new value
     */
    private function rewriteSql(array $map): string
    {
        $cases = '';
        foreach ($map as $from => $to) {
            $cases .= sprintf(" WHEN '%s' THEN '%s'", $from, $to);
        }

        $fromList = implode(', ', array_map(static fn (string $value): string => sprintf("'%s'", $value), array_keys($map)));
        $matches = sprintf("f->>'dimensionKey' = 'age_group' AND f->>'value' IN (%s)", $fromList);

        return <<<SQL
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(
                config_json::jsonb,
                '{query,filters}',
                (
                    SELECT jsonb_agg(
                        CASE WHEN {$matches}
                            THEN jsonb_set(f, '{value}', to_jsonb(CASE f->>'value'{$cases} END))
                            ELSE f
                        END
                        ORDER BY position
                    )
                    FROM jsonb_array_elements(config_json::jsonb #> '{query,filters}') WITH ORDINALITY AS t(f, position)
                )
            )::json
            WHERE jsonb_typeof(config_json::jsonb #> '{query,filters}') = 'array'
              AND EXISTS (
                  SELECT 1
                  FROM jsonb_array_elements(config_json::jsonb #> '{query,filters}') AS e(f)
                  WHERE {$matches}
              )
            SQL;
    }
}
