<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250821191201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE allocation ADD import_id INT NOT NULL');
        $this->addSql('ALTER TABLE allocation ADD CONSTRAINT FK_5C44232AB6A263D9 FOREIGN KEY (import_id) REFERENCES import (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5C44232AB6A263D9 ON allocation (import_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE allocation DROP CONSTRAINT FK_5C44232AB6A263D9');
        $this->addSql('DROP INDEX IDX_5C44232AB6A263D9');
        $this->addSql('ALTER TABLE allocation DROP import_id');
    }
}
