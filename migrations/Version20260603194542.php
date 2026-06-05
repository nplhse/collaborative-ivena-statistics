<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * No-op bookkeeping migration.
 *
 * Some environments executed this version from another feature branch before the
 * migration file was present on the current branch.
 */
final class Version20260603194542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op stub for analysis library migration executed on another branch.';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
