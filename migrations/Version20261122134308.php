<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Login link opt-in (#1355): users can sign in with a one-time link sent by email once they switch it on in their
 * settings. Existing users start with the option off.
 */
final class Version20261122134308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add login_link_enabled to users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD login_link_enabled BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP login_link_enabled');
    }
}
