<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page short descriptions (#1578): one optional sentence per translation, shown on subpage cards, help topic cards,
 * in the help search and as meta description. Existing translations have none; editors add them in the CMS.
 */
final class Version20261124111609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the short description of page translations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_translation ADD summary VARCHAR(300) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_translation DROP summary');
    }
}
