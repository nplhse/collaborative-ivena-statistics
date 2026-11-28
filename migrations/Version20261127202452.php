<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Account lock after too many failed passwords (#1762): sign-ins with password or login link are refused until
 * `login_locked_until`. Null for every existing account.
 */
final class Version20261127202452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.login_locked_until for the account lock after failed sign-ins.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD login_locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP login_locked_until');
    }
}
