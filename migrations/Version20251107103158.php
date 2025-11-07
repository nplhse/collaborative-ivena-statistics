<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251107103158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create agg_allocations_topcats for Top-N category aggregates per scope/period/dimension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE agg_allocations_top_categories (
                scope_type   TEXT NOT NULL,
                scope_id     TEXT NOT NULL,
                period_gran  TEXT NOT NULL,
                period_key   DATE NOT NULL,

                top_occasion    JSONB NOT NULL DEFAULT '{}'::jsonb,
                top_assignment  JSONB NOT NULL DEFAULT '{}'::jsonb,
                top_infection   JSONB NOT NULL DEFAULT '{}'::jsonb,
                top_indication  JSONB NOT NULL DEFAULT '{}'::jsonb,
                top_speciality  JSONB NOT NULL DEFAULT '{}'::jsonb,
                top_department  JSONB NOT NULL DEFAULT '{}'::jsonb,

                computed_at TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT agg_allocations_top_categories_pkey
                    PRIMARY KEY (scope_type, scope_id, period_gran, period_key)
            );
        ");

        // Index nach Zeitraum (wie bei hourly, counts, cohort_sums)
        $this->addSql('
            CREATE INDEX agg_top_categories_by_period
                ON agg_allocations_top_categories (period_gran, period_key);
        ');

        // Index nach Scope (wie bei deinen bisherigen Tabellen)
        $this->addSql('
            CREATE INDEX agg_top_categories_by_scope
                ON agg_allocations_top_categories (scope_type, scope_id);
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS agg_topcats_by_scope;');
        $this->addSql('DROP INDEX IF EXISTS agg_topcats_by_period;');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_top_categories;');
    }
}
