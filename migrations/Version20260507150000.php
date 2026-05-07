<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes for allocation cursor/keyset pagination.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_allocation_arrival_at_id ON allocation (arrival_at, id)');
        $this->addSql('CREATE INDEX idx_allocation_import_arrival_at_id ON allocation (import_id, arrival_at, id)');
        $this->addSql('CREATE INDEX idx_allocation_age_id ON allocation (age, id)');
        $this->addSql('CREATE INDEX idx_allocation_import_age_id ON allocation (import_id, age, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_allocation_import_age_id');
        $this->addSql('DROP INDEX idx_allocation_age_id');
        $this->addSql('DROP INDEX idx_allocation_import_arrival_at_id');
        $this->addSql('DROP INDEX idx_allocation_arrival_at_id');
    }
}
