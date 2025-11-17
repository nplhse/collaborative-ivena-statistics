<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251115162645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create agg_scope and transport time aggregation tables';
    }

    public function up(Schema $schema): void
    {
        // Create Scope table
        $this->addSql(<<<SQL
CREATE TABLE agg_scope (
  id          BIGSERIAL PRIMARY KEY,
  scope_type  TEXT NOT NULL,
  scope_id    TEXT NOT NULL,
  period_gran TEXT NOT NULL,
  period_key  DATE NOT NULL,
  UNIQUE (scope_type, scope_id, period_gran, period_key)
);
SQL);

        // Core transport time buckets
        $this->addSql(<<<SQL
CREATE TABLE agg_allocations_transport_time_buckets (
  agg_scope_id BIGINT PRIMARY KEY REFERENCES agg_scope(id) ON DELETE CASCADE,

  total              JSONB NOT NULL,
  with_physician     JSONB NOT NULL,
  resus_req          JSONB NOT NULL,
  cathlab_req        JSONB NOT NULL,
  gender_m           JSONB NOT NULL,
  gender_w           JSONB NOT NULL,
  gender_d           JSONB NOT NULL,
  urg_1              JSONB NOT NULL,
  urg_2              JSONB NOT NULL,
  urg_3              JSONB NOT NULL,
  transport_ground   JSONB NOT NULL,
  transport_air      JSONB NOT NULL,

  overall_minutes_mean      DOUBLE PRECISION,
  overall_minutes_variance  DOUBLE PRECISION,
  overall_minutes_stddev    DOUBLE PRECISION,

  computed_at TIMESTAMPTZ DEFAULT NOW()
);
SQL);

        // Dimension table
        $this->addSql(<<<SQL
CREATE TABLE agg_allocations_transport_time_dim (
  agg_scope_id      BIGINT NOT NULL REFERENCES agg_scope(id) ON DELETE CASCADE,
  dim_type          TEXT   NOT NULL,
  dim_id            INT    NOT NULL,
  bucket_key        TEXT   NOT NULL,

  n_total           INT    NOT NULL,
  n_with_physician  INT    NOT NULL,
  n_resus_req       INT    NOT NULL,
  n_cathlab_req     INT    NOT NULL,
  n_urg_1           INT    NOT NULL,
  n_urg_2           INT    NOT NULL,
  n_urg_3           INT    NOT NULL,
  n_transport_ground INT   NOT NULL,
  n_transport_air    INT   NOT NULL,

  mean_minutes      DOUBLE PRECISION,
  variance_minutes  DOUBLE PRECISION,
  stddev_minutes    DOUBLE PRECISION,

  computed_at       TIMESTAMPTZ DEFAULT NOW(),

  PRIMARY KEY (agg_scope_id, dim_type, dim_id, bucket_key)
);
SQL);

        // Optionale Indizes für häufige Abfragen auf der Dimensionstabelle
        $this->addSql('CREATE INDEX idx_agg_tt_dim_scope ON agg_allocations_transport_time_dim (agg_scope_id);');
        $this->addSql('CREATE INDEX idx_agg_tt_dim_type_dim ON agg_allocations_transport_time_dim (dim_type, dim_id);');
        $this->addSql('CREATE INDEX idx_agg_tt_dim_bucket ON agg_allocations_transport_time_dim (bucket_key);');
    }

    public function down(Schema $schema): void
    {
        // Reihenfolge beachten wegen FK-Constraints
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_transport_time_dim;');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_transport_time_buckets;');
        $this->addSql('DROP TABLE IF EXISTS agg_scope;');
    }
}
