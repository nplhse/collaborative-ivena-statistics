<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track whether an import export period was derived from rows or maintained manually.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import ADD export_period_source VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import DROP export_period_source');
    }
}
