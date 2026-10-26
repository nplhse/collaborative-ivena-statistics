<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hourly sample of the Messenger queues for the backlog history (issue #738, ADR 019).
 */
final class Version20261026122201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record an hourly sample of the Messenger queue backlog.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE messenger_queue_sample (
    sampled_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    pending INT NOT NULL,
    waiting INT NOT NULL,
    failed INT NOT NULL,
    oldest_pending_age_seconds INT DEFAULT NULL,
    PRIMARY KEY (sampled_at)
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_queue_sample');
    }
}
