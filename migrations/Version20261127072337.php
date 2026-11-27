<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Users have no public ID since v0.4-beta (#1424): profiles are addressed by username since #1353, and the
 * redirect of the old UUID profile URLs is removed. Down restores the column with new random values; the
 * old values are gone, so old UUID profile URLs stay dead.
 */
final class Version20261127072337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the public ID of users (#1424)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_user_public_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS public_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN public_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE "user" SET public_id = gen_random_uuid()::text');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_public_id ON "user" (public_id) WHERE public_id IS NOT NULL');
    }
}
