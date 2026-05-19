<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519125102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create overview materialized views for projection hospital counts and dimensions.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE MATERIALIZED VIEW mv_projection_state_hospital_count AS
SELECT state_id, COUNT(DISTINCT hospital_id)::int AS hospital_count
FROM allocation_stats_projection
WHERE state_id IS NOT NULL
GROUP BY state_id
SQL);
        $this->addSql('CREATE UNIQUE INDEX idx_mv_projection_state_hospital_count_state ON mv_projection_state_hospital_count (state_id)');

        $this->addSql(<<<'SQL'
CREATE MATERIALIZED VIEW mv_projection_dispatch_area_hospital_count AS
SELECT dispatch_area_id, COUNT(DISTINCT hospital_id)::int AS hospital_count
FROM allocation_stats_projection
WHERE dispatch_area_id IS NOT NULL
GROUP BY dispatch_area_id
SQL);
        $this->addSql('CREATE UNIQUE INDEX idx_mv_projection_dispatch_area_hospital_count_area ON mv_projection_dispatch_area_hospital_count (dispatch_area_id)');

        $this->addSql(<<<'SQL'
CREATE MATERIALIZED VIEW mv_projection_hospital_dimensions AS
SELECT
    hospital_id,
    MIN(state_id) AS state_id,
    MIN(dispatch_area_id) AS dispatch_area_id,
    MIN(hospital_location_code) AS hospital_location_code,
    MIN(hospital_tier_code) AS hospital_tier_code
FROM allocation_stats_projection
GROUP BY hospital_id
SQL);
        $this->addSql('CREATE UNIQUE INDEX idx_mv_projection_hospital_dimensions_hospital ON mv_projection_hospital_dimensions (hospital_id)');

        $this->addSql('REFRESH MATERIALIZED VIEW mv_projection_state_hospital_count');
        $this->addSql('REFRESH MATERIALIZED VIEW mv_projection_dispatch_area_hospital_count');
        $this->addSql('REFRESH MATERIALIZED VIEW mv_projection_hospital_dimensions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_projection_hospital_dimensions');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_projection_dispatch_area_hospital_count');
        $this->addSql('DROP MATERIALIZED VIEW IF EXISTS mv_projection_state_hospital_count');
    }
}
