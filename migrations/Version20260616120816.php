<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616120816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deduplication statistics columns to import table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import ADD rows_deduplicated INT DEFAULT NULL');
        $this->addSql('ALTER TABLE import ADD rows_deduplicated_discarded INT DEFAULT NULL');
        $this->addSql('ALTER TABLE import ADD rows_deduplicated_replaced INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import DROP rows_deduplicated_replaced');
        $this->addSql('ALTER TABLE import DROP rows_deduplicated_discarded');
        $this->addSql('ALTER TABLE import DROP rows_deduplicated');
    }
}
