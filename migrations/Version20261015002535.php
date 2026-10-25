<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015002535 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record one row per closure rebuild run as the long-term record for comparing rebuild phases.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE closure_rebuild_run (
    id VARCHAR(36) NOT NULL,
    triggered_by VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    full_rebuild BOOLEAN NOT NULL,
    hospital_ids JSON NOT NULL,
    started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    duration_ms INT DEFAULT NULL,
    passes INT DEFAULT 0 NOT NULL,
    events INT DEFAULT 0 NOT NULL,
    partitions INT DEFAULT 0 NOT NULL,
    hours_written BIGINT DEFAULT 0 NOT NULL,
    publish_ms INT DEFAULT NULL,
    peak_memory_bytes BIGINT DEFAULT NULL,
    request_count INT DEFAULT 0 NOT NULL,
    error_class VARCHAR(255) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX idx_closure_rebuild_run_started ON closure_rebuild_run (started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE closure_rebuild_run');
    }
}
