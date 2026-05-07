<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507133058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional secondary indication (raw + normalized) on allocation and projection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation ADD secondary_indication_raw_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE allocation ADD secondary_indication_normalized_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE allocation ADD CONSTRAINT FK_allocation_secondary_indication_raw FOREIGN KEY (secondary_indication_raw_id) REFERENCES indication_raw (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE allocation ADD CONSTRAINT FK_allocation_secondary_indication_normalized FOREIGN KEY (secondary_indication_normalized_id) REFERENCES indication_normalized (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_allocation_secondary_indication_raw ON allocation (secondary_indication_raw_id)');
        $this->addSql('CREATE INDEX IDX_allocation_secondary_indication_normalized ON allocation (secondary_indication_normalized_id)');

        $this->addSql('ALTER TABLE allocation_stats_projection ADD secondary_indication_normalized_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_asp_secondary_indication_normalized_id ON allocation_stats_projection (secondary_indication_normalized_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_asp_secondary_indication_normalized_id');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP secondary_indication_normalized_id');

        $this->addSql('ALTER TABLE allocation DROP CONSTRAINT FK_allocation_secondary_indication_raw');
        $this->addSql('ALTER TABLE allocation DROP CONSTRAINT FK_allocation_secondary_indication_normalized');
        $this->addSql('DROP INDEX IDX_allocation_secondary_indication_raw');
        $this->addSql('DROP INDEX IDX_allocation_secondary_indication_normalized');
        $this->addSql('ALTER TABLE allocation DROP secondary_indication_raw_id');
        $this->addSql('ALTER TABLE allocation DROP secondary_indication_normalized_id');
    }
}
