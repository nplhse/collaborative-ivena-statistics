<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two-factor authentication with an authenticator app (#1357, ADR 040): encrypted TOTP secret, the time it was
 * switched on and the hashes of the unused backup codes. Existing users start without two-factor authentication.
 */
final class Version20261122165431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add two-factor authentication columns to users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD totp_secret_encrypted TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD totp_enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD backup_code_hashes JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP totp_secret_encrypted');
        $this->addSql('ALTER TABLE "user" DROP totp_enabled_at');
        $this->addSql('ALTER TABLE "user" DROP backup_code_hashes');
    }
}
