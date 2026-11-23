<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reaction notifications now name people (#973). Entries from before keep their numbers and read "3 people reacted".
 */
final class Version20261123090955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark existing reaction notifications as naming nobody.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE notification SET parameters = CAST(CAST(parameters AS JSONB) || '{\"actors\": \"none\"}' AS JSON) WHERE type = 'content.reaction_received' AND parameters->>'actors' IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE notification SET parameters = CAST(CAST(parameters AS JSONB) - 'actors' - 'firstActor' - 'secondActor' - 'others' - 'actorIds' AS JSON) WHERE type = 'content.reaction_received'");
    }
}
