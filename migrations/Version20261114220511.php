<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the optional display name of a user (#462). Existing users keep NULL and are shown by their username.
 */
final class Version20261114220511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional display_name to user (#462)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD display_name VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP display_name');
    }
}
