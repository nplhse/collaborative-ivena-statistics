<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922131135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visibility to saved explorer views. Existing rows stay private.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE saved_explorer_view ADD visibility VARCHAR(20) DEFAULT 'private' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saved_explorer_view DROP visibility');
    }
}
