<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023191944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Monthly reminder dispatches remember their current send attempt, so failures of earlier attempts do not change them (ADR 016)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monthly_reminder_dispatch ADD attempt_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monthly_reminder_dispatch DROP attempt_id');
    }
}
