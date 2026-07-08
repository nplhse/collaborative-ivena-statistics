<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708183119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable public_id (UUID v4 RFC 4122) columns to explore detail resources with partial unique indexes.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD COLUMN IF NOT EXISTS public_id VARCHAR(36) DEFAULT NULL',
                $table,
            ));
            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uniq_%s_public_id ON %s (public_id) WHERE public_id IS NOT NULL',
                $table,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('DROP INDEX CONCURRENTLY IF EXISTS uniq_%s_public_id', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS public_id', $table));
        }
    }

    /** @var list<string> */
    private const array TABLES = [
        'hospital',
        'allocation',
        'mci_case',
        'secondary_transport',
        'indication_raw',
    ];
}
