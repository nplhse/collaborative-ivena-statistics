<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * No-op bookkeeping migration.
 *
 * Local databases may already record this version from an earlier draft that was
 * superseded by Version20260604155217.
 */
final class Version20260604120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op stub superseded by Version20260604155217.';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
