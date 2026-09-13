<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913151535 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a partial index on closed-department rows of allocation_stats_projection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_asp_closed_department_hospital_created ON allocation_stats_projection (hospital_id, created_at) WHERE department_was_closed IS TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_asp_closed_department_hospital_created');
    }
}
