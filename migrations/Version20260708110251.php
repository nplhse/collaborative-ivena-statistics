<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708110251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery tracking columns to monthly_reminder_dispatch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE monthly_reminder_dispatch ADD status VARCHAR(16) DEFAULT 'queued' NOT NULL");
        $this->addSql("ALTER TABLE monthly_reminder_dispatch ADD recipient_email VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE monthly_reminder_dispatch ADD failure_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE monthly_reminder_dispatch ADD delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE monthly_reminder_dispatch SET status = 'sent' WHERE status = 'queued'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monthly_reminder_dispatch DROP delivered_at');
        $this->addSql('ALTER TABLE monthly_reminder_dispatch DROP failure_reason');
        $this->addSql('ALTER TABLE monthly_reminder_dispatch DROP recipient_email');
        $this->addSql('ALTER TABLE monthly_reminder_dispatch DROP status');
    }
}
