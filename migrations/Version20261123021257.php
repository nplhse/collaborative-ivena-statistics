<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Boundaries of grouped reaction notifications (#973). Existing reactions are marked as handled, so they are never
 * reported as new after the release.
 */
final class Version20261123021257 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reaction_notification_state and mark existing reactions as handled.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reaction_notification_state (target_type VARCHAR(32) NOT NULL, target_id INT NOT NULL, notification_id INT DEFAULT NULL, floor_reaction_id INT NOT NULL, through_reaction_id INT NOT NULL, PRIMARY KEY (target_type, target_id))');
        $this->addSql('INSERT INTO reaction_notification_state (target_type, target_id, notification_id, floor_reaction_id, through_reaction_id) SELECT target_type, target_id, NULL, MAX(id), MAX(id) FROM reaction GROUP BY target_type, target_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reaction_notification_state');
    }
}
