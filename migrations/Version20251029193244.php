<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251029193244 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        // 1. Aggregat-Tabellen
        $this->addSql(<<<'SQL'
CREATE TABLE agg_allocations_counts (
    scope_type     TEXT        NOT NULL,
    scope_id       TEXT        NOT NULL,
    period_gran    TEXT        NOT NULL,
    period_key     DATE        NOT NULL,

    total                  INTEGER NOT NULL,

    gender_m               INTEGER NOT NULL,
    gender_w               INTEGER NOT NULL,
    gender_d               INTEGER NOT NULL,
    gender_u               INTEGER NOT NULL,

    urg_1                  INTEGER NOT NULL,
    urg_2                  INTEGER NOT NULL,
    urg_3                  INTEGER NOT NULL,

    cathlab_required       INTEGER NOT NULL,
    resus_required         INTEGER NOT NULL,

    is_cpr                 INTEGER NOT NULL,
    is_ventilated          INTEGER NOT NULL,
    is_shock               INTEGER NOT NULL,
    is_pregnant            INTEGER NOT NULL,
    with_physician         INTEGER NOT NULL,
    infectious             INTEGER NOT NULL,

    computed_at            TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),

    PRIMARY KEY (scope_type, scope_id, period_gran, period_key)
);
SQL);

        $this->addSql('CREATE INDEX agg_counts_by_period ON agg_allocations_counts (period_gran, period_key);');

        $this->addSql(<<<'SQL'
CREATE TABLE agg_allocations_hourly (
    scope_type   TEXT NOT NULL,
    scope_id     TEXT NOT NULL,
    period_gran  TEXT NOT NULL,
    period_key   DATE NOT NULL,

    hours_count  INTEGER[24] NOT NULL,

    computed_at  TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),

    PRIMARY KEY (scope_type, scope_id, period_gran, period_key)
);
SQL);

        $this->addSql('CREATE INDEX agg_hourly_by_period ON agg_allocations_hourly (period_gran, period_key);');

        // 2. Hilfsfunktionen
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION period_day(ts timestamp)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT (ts AT TIME ZONE 'Europe/Berlin')::date;
$$;
SQL);

        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION period_week(ts timestamp)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT date_trunc('week', ts AT TIME ZONE 'Europe/Berlin')::date;
$$;
SQL);

        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION period_quarter(ts timestamp)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT date_trunc('quarter', ts AT TIME ZONE 'Europe/Berlin')::date;
$$;
SQL);

        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION period_year(ts timestamp)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT date_trunc('year', ts AT TIME ZONE 'Europe/Berlin')::date;
$$;
SQL);
    }

    public function down(Schema $schema): void
    {
        // Achtung: Reihenfolge reversed
        $this->addSql('DROP FUNCTION period_year(timestamp);');
        $this->addSql('DROP FUNCTION period_quarter(timestamp);');
        $this->addSql('DROP FUNCTION period_week(timestamp);');
        $this->addSql('DROP FUNCTION period_day(timestamp);');

        $this->addSql('DROP TABLE agg_allocations_hourly;');
        $this->addSql('DROP TABLE agg_allocations_counts;');
    }
}
