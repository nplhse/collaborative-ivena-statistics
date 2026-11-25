<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Optional icon per page (#1590), shown on help topic cards and subpage cards. Existing pages have none.
 */
final class Version20261124205116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional icon to pages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page ADD icon VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page DROP icon');
    }
}
