<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The weekly feedback quarantine summary (issue #964) is opt-in. Administrators that exist at deploy time
 * get it switched on for in-app and email; they can switch it off on the notification settings page.
 * Existing choices are kept.
 */
final class Version20261113085424 extends AbstractMigration
{
    private const string TYPE = 'admin.feedback_quarantine_summary';

    public function getDescription(): string
    {
        return 'Switch the feedback quarantine summary on for existing administrators.';
    }

    public function up(Schema $schema): void
    {
        foreach (['in_app', 'email'] as $channel) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled)
                    SELECT id, 'type', :type, :channel, true
                    FROM "user"
                    WHERE jsonb_exists(roles::jsonb, 'ROLE_ADMIN')
                    ON CONFLICT (user_id, scope, preference_key, channel) DO NOTHING
                    SQL,
                ['type' => self::TYPE, 'channel' => $channel],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM user_notification_preference WHERE scope = 'type' AND preference_key = :type",
            ['type' => self::TYPE],
        );
    }
}
