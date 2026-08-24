<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821194242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project activity feed index on user_activity (occurred_at, id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_user_activity_project_feed ON user_activity (occurred_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_activity_project_feed');
    }
}
