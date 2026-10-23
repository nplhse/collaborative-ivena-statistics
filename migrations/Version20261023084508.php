<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023084508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Monthly reminder opt-out becomes a notification preference override (ADR 016, step 7b); the user column stays until a later release';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled)
            SELECT id, 'type', 'engagement.monthly_reminder', 'email', false
            FROM "user"
            WHERE receives_monthly_submission_reminder = false
            ON CONFLICT (user_id, scope, preference_key, channel) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user" SET receives_monthly_submission_reminder = false
            WHERE id IN (
                SELECT user_id FROM user_notification_preference
                WHERE scope = 'type' AND preference_key = 'engagement.monthly_reminder' AND channel = 'email' AND enabled = false
            )
            SQL);
        $this->addSql("DELETE FROM user_notification_preference WHERE scope = 'type' AND preference_key = 'engagement.monthly_reminder'");
    }
}
