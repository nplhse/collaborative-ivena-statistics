<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812184837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable public_id (UUID v4) to user with backfill and partial unique index.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS public_id VARCHAR(36) DEFAULT NULL');
        $this->addSql(<<<'SQL'
UPDATE "user"
SET public_id = gen_random_uuid()::text
WHERE public_id IS NULL
SQL);
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uniq_user_public_id ON "user" (public_id) WHERE public_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS uniq_user_public_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS public_id');
    }
}
