<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251108121403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create agg_allocations_age_buckets: per-aspect age buckets + global age mean';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE agg_allocations_age_buckets (
  scope_type   text        NOT NULL,
  scope_id     text        NOT NULL,
  period_gran  text        NOT NULL,
  period_key   date        NOT NULL,

  total             jsonb NOT NULL DEFAULT '[]'::jsonb,
  gender_m          jsonb NOT NULL DEFAULT '[]'::jsonb,
  gender_w          jsonb NOT NULL DEFAULT '[]'::jsonb,
  gender_d          jsonb NOT NULL DEFAULT '[]'::jsonb,
  urg_1             jsonb NOT NULL DEFAULT '[]'::jsonb,
  urg_2             jsonb NOT NULL DEFAULT '[]'::jsonb,
  urg_3             jsonb NOT NULL DEFAULT '[]'::jsonb,
  cathlab_required  jsonb NOT NULL DEFAULT '[]'::jsonb,
  resus_required    jsonb NOT NULL DEFAULT '[]'::jsonb,
  is_cpr            jsonb NOT NULL DEFAULT '[]'::jsonb,
  is_ventilated     jsonb NOT NULL DEFAULT '[]'::jsonb,
  is_shock          jsonb NOT NULL DEFAULT '[]'::jsonb,
  is_pregnant       jsonb NOT NULL DEFAULT '[]'::jsonb,
  with_physician    jsonb NOT NULL DEFAULT '[]'::jsonb,
  infectious        jsonb NOT NULL DEFAULT '[]'::jsonb,

  overall_age_mean      double precision,
  overall_age_variance  double precision,
  overall_age_stddev    double precision,

  computed_at  timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT agg_allocations_age_buckets_pkey
    PRIMARY KEY (scope_type, scope_id, period_gran, period_key)
);
SQL);

        $this->addSql(<<<'SQL'
CREATE INDEX IF NOT EXISTS idx_age_buckets_period
  ON agg_allocations_age_buckets (period_key);
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_age_buckets');
    }
}
