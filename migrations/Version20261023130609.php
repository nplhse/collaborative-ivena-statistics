<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023130609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual monthly reminders use engagement.monthly_reminder and the owner\'s settings (ADR 016)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE notification
            SET type = 'engagement.monthly_reminder',
                title_key = 'engagement.monthly_reminder.title',
                message_key = 'engagement.monthly_reminder.message'
            WHERE type = 'engagement.monthly_reminder_manual'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible on purpose: former manual reminders cannot be told apart afterwards.
    }
}
