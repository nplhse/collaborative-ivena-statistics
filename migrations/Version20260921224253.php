<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921224253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index mci_case.mci_id for MCI grouping lookups. The column stays non-unique because the source identifier groups cases and is not a global identity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_mci_case_mci_id ON mci_case (mci_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_mci_case_mci_id');
    }
}
