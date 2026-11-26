<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pinned blog posts and announcements (#1486): a pinned post stands above the others until it is unpinned; announced_at
 * records when a post was sent as an announcement notification, so it is sent only once. Existing posts are neither.
 */
final class Version20261122122215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pinned and announced_at to blog posts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post ADD pinned BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE post ADD announced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post DROP announced_at');
        $this->addSql('ALTER TABLE post DROP pinned');
    }
}
