<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ends the transition from public/uploads/media to the media storage (#1450, ADR 039): drops the old file name
 * columns `filename` and `legacy_filename` with their indexes and makes `storage_key` required.
 *
 * The migration stops while a media item has no storage key, that is while its file has not been moved yet; such an
 * installation has to run `app:media:migrate-storage` on a v0.3 release first.
 *
 * down() restores the columns and indexes as nullable columns, but not their values: the old file names are lost.
 */
final class Version20261127074154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the old media file name columns and make the media storage key required.';
    }

    public function up(Schema $schema): void
    {
        $notMoved = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media WHERE storage_key IS NULL');
        $this->abortIf(
            $notMoved > 0,
            \sprintf(
                '%d media item(s) have no storage key: their files are still in public/uploads/media. Deploy a v0.3 release, run app:media:migrate-storage there, then migrate again.',
                $notMoved,
            ),
        );

        $this->addSql('DROP INDEX IF EXISTS idx_media_filename');
        $this->addSql('DROP INDEX IF EXISTS idx_media_legacy_filename');
        $this->addSql('ALTER TABLE media DROP filename');
        $this->addSql('ALTER TABLE media DROP legacy_filename');
        $this->addSql('ALTER TABLE media ALTER storage_key SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ALTER storage_key DROP NOT NULL');
        $this->addSql('ALTER TABLE media ADD filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD legacy_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_media_filename ON media (filename)');
        $this->addSql('CREATE INDEX idx_media_legacy_filename ON media (legacy_filename)');
    }
}
