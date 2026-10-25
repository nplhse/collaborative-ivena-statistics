<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tells every enabled user once that notifications exist (ADR 016): one web entry of the type
 * announcement.new_feature that opens the notification settings. Written like the publisher writes it
 * (entry, deduplication key, badge state); the deduplication key keeps a repeated run from adding more.
 */
final class Version20261023130830 extends AbstractMigration
{
    private const string KEY = 'announcement.new_feature:notifications';

    public function getDescription(): string
    {
        return 'Announce the notification system to every enabled user with one web notification';
    }

    public function up(Schema $schema): void
    {
        $now = new \DateTimeImmutable();

        // One statement: only the rows inserted here get a deduplication key and count for the badge.
        $this->addSql(
            <<<'SQL'
                WITH inserted AS (
                    INSERT INTO notification (
                        recipient_id, type, category, priority, translation_domain, title_key, message_key,
                        parameters, action_route, action_parameters, access_scope, scope_reference_id, required_role,
                        metadata, in_app_visible, created_at, toast_pending_until
                    )
                    SELECT u.id, 'announcement.new_feature', 'announcements', 'normal', 'notification',
                           'announcement.new_feature.title', 'announcement.new_feature.message',
                           '{"feature":"notifications"}', 'app_settings_notifications', '[]', 'none', NULL, NULL,
                           '{"reference":"feature:notifications"}', true, :now, NULL
                    FROM "user" u
                    WHERE u.is_enabled = true
                      AND NOT EXISTS (
                          SELECT 1 FROM notification_deduplication d
                          WHERE d.recipient_id = u.id AND d.deduplication_key = :key
                      )
                    RETURNING id, recipient_id
                ),
                keys AS (
                    INSERT INTO notification_deduplication (recipient_id, deduplication_key, expires_at, notification_id)
                    SELECT recipient_id, :key, :expires, id FROM inserted
                    ON CONFLICT (recipient_id, deduplication_key) DO NOTHING
                )
                INSERT INTO notification_recipient_state (user_id, version, unread_count, updated_at)
                SELECT recipient_id, 1, 1, :now FROM inserted
                ON CONFLICT (user_id) DO UPDATE
                    SET version = notification_recipient_state.version + 1,
                        unread_count = notification_recipient_state.unread_count + 1,
                        updated_at = EXCLUDED.updated_at
                SQL,
            [
                'key' => self::KEY,
                // Timestamps in the application's time zone, like the publisher writes them (the database runs in UTC).
                'now' => $now->format('Y-m-d H:i:s'),
                'expires' => $now->modify('+7 days')->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM notification WHERE type = 'announcement.new_feature' AND metadata::jsonb ->> 'reference' = 'feature:notifications'");
        $this->addSql('DELETE FROM notification_deduplication WHERE deduplication_key = :key', ['key' => self::KEY]);
        // The badge state is recounted by the daily clean-up.
    }
}
