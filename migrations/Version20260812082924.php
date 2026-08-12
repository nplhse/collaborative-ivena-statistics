<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812082924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional show_toc flag to page_translation for frontend table of contents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_translation ADD show_toc BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_translation DROP show_toc');
    }
}
