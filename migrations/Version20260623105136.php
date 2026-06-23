<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623105136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add monthly_reminder_dispatch table for scheduler idempotency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE monthly_reminder_dispatch (id SERIAL NOT NULL, hospital_id INT NOT NULL, reporting_period VARCHAR(7) NOT NULL, trigger VARCHAR(16) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_monthly_reminder_dispatch ON monthly_reminder_dispatch (hospital_id, reporting_period, trigger)');
        $this->addSql('CREATE INDEX IDX_MONTHLY_REMINDER_DISPATCH_HOSPITAL ON monthly_reminder_dispatch (hospital_id)');
        $this->addSql('ALTER TABLE monthly_reminder_dispatch ADD CONSTRAINT FK_MONTHLY_REMINDER_DISPATCH_HOSPITAL FOREIGN KEY (hospital_id) REFERENCES hospital (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monthly_reminder_dispatch DROP CONSTRAINT FK_MONTHLY_REMINDER_DISPATCH_HOSPITAL');
        $this->addSql('DROP TABLE monthly_reminder_dispatch');
    }
}
