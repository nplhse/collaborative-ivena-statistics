<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Draft copy of page translation content for the block editor's autosave (#967, ADR 036). The public page keeps
 * reading `content`; the draft is copied into it by an explicit save. Existing rows get no draft and revision 0.
 *
 * `down()` drops the columns together with any unsaved drafts.
 */
final class Version20261114204545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add draft content, revision and timestamp to page_translation (#967)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_translation ADD draft_content JSON DEFAULT NULL, ADD draft_revision INT DEFAULT 0 NOT NULL, ADD draft_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_translation DROP draft_content, DROP draft_revision, DROP draft_updated_at');
    }
}
