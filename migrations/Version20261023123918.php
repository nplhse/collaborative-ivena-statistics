<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin notifications go to every administrator; each admin chooses them on the notification settings
 * page. Admins without the former opt-in role keep receiving nothing: they get switched-off overrides
 * for those types. Then both roles are removed from all users.
 */
final class Version20261023123918 extends AbstractMigration
{
    private const array TYPES_BY_ROLE = [
        'ROLE_RECEIVES_NOTIFICATION' => ['admin.user_registered', 'admin.import_failed'],
        'ROLE_FEEDBACK_RECIPIENT' => ['admin.feedback_received'],
    ];

    public function getDescription(): string
    {
        return 'Replace ROLE_RECEIVES_NOTIFICATION and ROLE_FEEDBACK_RECIPIENT with notification preferences (ADR 016)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TYPES_BY_ROLE as $role => $types) {
            foreach ($types as $type) {
                foreach (['in_app', 'email'] as $channel) {
                    $this->addSql(
                        <<<'SQL'
                            INSERT INTO user_notification_preference (user_id, scope, preference_key, channel, enabled)
                            SELECT id, 'type', :type, :channel, false
                            FROM "user"
                            WHERE jsonb_exists(roles::jsonb, 'ROLE_ADMIN') AND NOT jsonb_exists(roles::jsonb, :role)
                            ON CONFLICT (user_id, scope, preference_key, channel) DO NOTHING
                            SQL,
                        ['type' => $type, 'channel' => $channel, 'role' => $role],
                    );
                }
            }
        }

        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET roles = (
                SELECT COALESCE(json_agg(role), '[]'::json)
                FROM json_array_elements_text(roles) AS role
                WHERE role NOT IN ('ROLE_RECEIVES_NOTIFICATION', 'ROLE_FEEDBACK_RECIPIENT')
            )
            WHERE jsonb_exists_any(roles::jsonb, array['ROLE_RECEIVES_NOTIFICATION', 'ROLE_FEEDBACK_RECIPIENT'])
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Admins who did not switch a type off get the role back; the overrides stay as user settings.
        foreach (self::TYPES_BY_ROLE as $role => $types) {
            $this->addSql(
                <<<'SQL'
                    UPDATE "user"
                    SET roles = (roles::jsonb || to_jsonb(CAST(:role AS text)))::json
                    WHERE jsonb_exists(roles::jsonb, 'ROLE_ADMIN')
                      AND NOT jsonb_exists(roles::jsonb, :role)
                      AND NOT EXISTS (
                          SELECT 1 FROM user_notification_preference p
                          WHERE p.user_id = "user".id AND p.scope = 'type' AND p.preference_key = :type AND p.enabled = false
                      )
                    SQL,
                ['role' => $role, 'type' => $types[0]],
            );
        }
    }
}
