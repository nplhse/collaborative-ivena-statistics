<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260321143212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create allocation_stats_projection: denormalized allocation facts for analytics.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE allocation_stats_projection (
    id INT NOT NULL,
    import_id INT NOT NULL,
    hospital_id INT NOT NULL,
    state_id INT NOT NULL,
    dispatch_area_id INT NOT NULL,
    speciality_id INT NOT NULL,
    department_id INT NOT NULL,
    occasion_id INT DEFAULT NULL,
    assignment_id INT NOT NULL,
    infection_id INT DEFAULT NULL,
    indication_normalized_id INT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    arrival_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    created_year SMALLINT NOT NULL,
    created_quarter SMALLINT NOT NULL,
    created_month SMALLINT NOT NULL,
    created_week SMALLINT NOT NULL,
    created_day SMALLINT NOT NULL,
    created_weekday SMALLINT NOT NULL,
    created_hour SMALLINT NOT NULL,
    transport_time_minutes INT NOT NULL,
    age INT DEFAULT NULL,
    gender_code SMALLINT DEFAULT NULL,
    urgency_code SMALLINT NOT NULL,
    transport_type_code SMALLINT DEFAULT NULL,
    requires_resus BOOLEAN DEFAULT NULL,
    requires_cathlab BOOLEAN DEFAULT NULL,
    is_cpr BOOLEAN DEFAULT NULL,
    is_ventilated BOOLEAN DEFAULT NULL,
    is_with_physician BOOLEAN DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);

        $this->addSql('CREATE INDEX idx_asp_import_id ON allocation_stats_projection (import_id)');
        $this->addSql('CREATE INDEX idx_asp_created_at ON allocation_stats_projection (created_at)');
        $this->addSql('CREATE INDEX idx_asp_created_year ON allocation_stats_projection (created_year)');
        $this->addSql('CREATE INDEX idx_asp_created_quarter ON allocation_stats_projection (created_quarter)');
        $this->addSql('CREATE INDEX idx_asp_created_month ON allocation_stats_projection (created_month)');
        $this->addSql('CREATE INDEX idx_asp_created_week ON allocation_stats_projection (created_week)');
        $this->addSql('CREATE INDEX idx_asp_created_day ON allocation_stats_projection (created_day)');
        $this->addSql('CREATE INDEX idx_asp_created_weekday ON allocation_stats_projection (created_weekday)');
        $this->addSql('CREATE INDEX idx_asp_created_hour ON allocation_stats_projection (created_hour)');
        $this->addSql('CREATE INDEX idx_asp_arrival_at ON allocation_stats_projection (arrival_at)');
        $this->addSql('CREATE INDEX idx_asp_hospital_id ON allocation_stats_projection (hospital_id)');
        $this->addSql('CREATE INDEX idx_asp_state_id ON allocation_stats_projection (state_id)');
        $this->addSql('CREATE INDEX idx_asp_dispatch_area_id ON allocation_stats_projection (dispatch_area_id)');
        $this->addSql('CREATE INDEX idx_asp_urgency_code ON allocation_stats_projection (urgency_code)');
        $this->addSql('CREATE INDEX idx_asp_transport_type_code ON allocation_stats_projection (transport_type_code)');
        $this->addSql('CREATE INDEX idx_asp_gender_code ON allocation_stats_projection (gender_code)');
        $this->addSql('CREATE INDEX idx_asp_age ON allocation_stats_projection (age)');
        $this->addSql('CREATE INDEX idx_asp_speciality_id ON allocation_stats_projection (speciality_id)');
        $this->addSql('CREATE INDEX idx_asp_department_id ON allocation_stats_projection (department_id)');
        $this->addSql('CREATE INDEX idx_asp_indication_normalized_id ON allocation_stats_projection (indication_normalized_id)');
        $this->addSql('CREATE INDEX idx_asp_occasion_id ON allocation_stats_projection (occasion_id)');
        $this->addSql('CREATE INDEX idx_asp_assignment_id ON allocation_stats_projection (assignment_id)');
        $this->addSql('CREATE INDEX idx_asp_infection_id ON allocation_stats_projection (infection_id)');
        $this->addSql('CREATE INDEX idx_asp_is_with_physician ON allocation_stats_projection (is_with_physician)');
        $this->addSql('CREATE INDEX idx_asp_requires_resus ON allocation_stats_projection (requires_resus)');
        $this->addSql('CREATE INDEX idx_asp_requires_cathlab ON allocation_stats_projection (requires_cathlab)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS allocation_stats_projection');
    }
}
