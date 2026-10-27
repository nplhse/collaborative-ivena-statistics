<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compact data coverage summary per import for the import detail page (issue #778).
 */
final class Version20261026211003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the data coverage summary of an import.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import ADD data_coverage JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import DROP data_coverage');
    }
}
