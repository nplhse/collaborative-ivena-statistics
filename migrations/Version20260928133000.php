<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add period indexes for closure interval analytics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_closure_interval_period ON closure_interval (starts_at, ends_at)');
        $this->addSql('CREATE INDEX idx_closure_interval_hospital_period ON closure_interval (hospital_id, starts_at, ends_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_closure_interval_period');
        $this->addSql('DROP INDEX idx_closure_interval_hospital_period');
    }
}
