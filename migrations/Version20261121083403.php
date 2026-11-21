<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Managed media storage (#489, ADR 039): storage key, checksum and last verification of the stored file, the old file
 * name of media moved from public/uploads/media, and the visibility of a media item. The columns only add to the
 * table, so the previous release keeps working until the deploy switches over.
 */
final class Version20261121083403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add storage key, checksum, verification time, legacy file name and visibility to media.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD storage_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD checksum_sha256 VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD legacy_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql("ALTER TABLE media ADD visibility VARCHAR(16) DEFAULT 'public' NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX uniq_media_storage_key ON media (storage_key)');
        $this->addSql('CREATE INDEX idx_media_legacy_filename ON media (legacy_filename)');
        $this->addSql('CREATE INDEX idx_media_filename ON media (filename)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_filename');
        $this->addSql('DROP INDEX idx_media_legacy_filename');
        $this->addSql('DROP INDEX uniq_media_storage_key');
        $this->addSql('ALTER TABLE media DROP visibility');
        $this->addSql('ALTER TABLE media DROP legacy_filename');
        $this->addSql('ALTER TABLE media DROP verified_at');
        $this->addSql('ALTER TABLE media DROP checksum_sha256');
        $this->addSql('ALTER TABLE media DROP storage_key');
    }
}
