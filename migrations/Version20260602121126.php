<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602121126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional unique page_key to page table for system-known pages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page ADD key VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_page_key ON page (key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_page_key');
        $this->addSql('ALTER TABLE page DROP key');
    }
}
