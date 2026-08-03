<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analysis_family and topics library metadata columns to saved_explorer_view.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saved_explorer_view ADD analysis_family VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE saved_explorer_view ADD topics JSON DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_saved_explorer_view_analysis_family ON saved_explorer_view (analysis_family)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_saved_explorer_view_analysis_family');
        $this->addSql('ALTER TABLE saved_explorer_view DROP analysis_family');
        $this->addSql('ALTER TABLE saved_explorer_view DROP topics');
    }
}
