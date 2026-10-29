<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step progress of the background stage of generation runs (issue #791, ADR 023), for the wizard's summary.
 */
final class Version20261029093539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add step progress to generation_run and generation_run_hospital.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE generation_run_hospital ADD projection_step VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE generation_run_hospital ADD projection_done INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE generation_run_hospital ADD projection_total INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE generation_run ADD finish_step VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE generation_run DROP finish_step');
        $this->addSql('ALTER TABLE generation_run_hospital DROP projection_total');
        $this->addSql('ALTER TABLE generation_run_hospital DROP projection_done');
        $this->addSql('ALTER TABLE generation_run_hospital DROP projection_step');
    }
}
