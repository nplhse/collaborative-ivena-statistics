<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251103141544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE agg_allocations_cohort_sums (
  scope_type   TEXT NOT NULL,
  scope_id     TEXT NOT NULL,
  period_gran  TEXT NOT NULL,
  period_key   DATE NOT NULL,

  total                INTEGER NOT NULL,
  gender_m             INTEGER NOT NULL,
  gender_w             INTEGER NOT NULL,
  gender_d             INTEGER NOT NULL,
  gender_u             INTEGER NOT NULL,

  urg_1                INTEGER NOT NULL,
  urg_2                INTEGER NOT NULL,
  urg_3                INTEGER NOT NULL,

  cathlab_required     INTEGER NOT NULL,
  resus_required       INTEGER NOT NULL,

  is_cpr               INTEGER NOT NULL,
  is_ventilated        INTEGER NOT NULL,
  is_shock             INTEGER NOT NULL,
  is_pregnant          INTEGER NOT NULL,
  with_physician       INTEGER NOT NULL,
  infectious           INTEGER NOT NULL,

  computed_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (scope_type, scope_id, period_gran, period_key)
);
SQL);

        $this->addSql('CREATE INDEX agg_cohort_sums_by_period ON agg_allocations_cohort_sums (period_gran, period_key);');

        $this->addSql(<<<'SQL'
CREATE TABLE agg_allocations_cohort_stats (
  scope_type   TEXT NOT NULL,
  scope_id     TEXT NOT NULL,
  period_gran  TEXT NOT NULL,
  period_key   DATE NOT NULL,

  n            INTEGER NOT NULL,
  mean_total   NUMERIC(12,4) NOT NULL,
  rates        JSONB NOT NULL,

  computed_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (scope_type, scope_id, period_gran, period_key)
);
SQL);

        $this->addSql('CREATE INDEX agg_cohort_stats_by_period ON agg_allocations_cohort_stats (period_gran, period_key);');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_cohort_stats;');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_cohort_sums;');
    }
}
