<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One run log for all long-running background jobs (issue #693, ADR 018).
 * closure_rebuild_run stored Europe/Berlin wall time; job_run stores instants (TIMESTAMPTZ).
 */
final class Version20261025174814 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record runs of every background job in job_run and move the closure rebuild runs into it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE job_run (
    id VARCHAR(36) NOT NULL,
    job VARCHAR(48) NOT NULL,
    triggered_by VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    attempt SMALLINT DEFAULT 1 NOT NULL,
    import_id INT DEFAULT NULL,
    import_run_id UUID DEFAULT NULL,
    hospital_id INT DEFAULT NULL,
    started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
    duration_ms INT DEFAULT NULL,
    peak_memory_bytes BIGINT DEFAULT NULL,
    thresholds_exceeded VARCHAR(64) DEFAULT NULL,
    error_class VARCHAR(255) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    metrics JSONB DEFAULT '{}' NOT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX idx_job_run_job_started ON job_run (job, started_at DESC)');
        $this->addSql('CREATE INDEX idx_job_run_import ON job_run (import_id)');
        $this->addSql("CREATE INDEX idx_job_run_running ON job_run (job) WHERE status = 'running'");

        $this->addSql(<<<'SQL'
INSERT INTO job_run (id, job, triggered_by, status, started_at, finished_at, duration_ms, peak_memory_bytes, error_class, error_message, metrics)
SELECT id, 'closure_rebuild', triggered_by, status,
       started_at AT TIME ZONE 'Europe/Berlin',
       finished_at AT TIME ZONE 'Europe/Berlin',
       duration_ms, peak_memory_bytes, error_class, error_message,
       jsonb_strip_nulls(jsonb_build_object(
           'full', full_rebuild,
           'hospital_ids', hospital_ids::jsonb,
           'passes', passes,
           'events', events,
           'partitions', partitions,
           'hours_written', hours_written,
           'publish_ms', publish_ms,
           'requests', request_count,
           'queries', query_count,
           'reference_window', reference_window
       ))
FROM closure_rebuild_run
SQL);
        $this->addSql('DROP TABLE closure_rebuild_run');
    }

    public function down(Schema $schema): void
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
    reference_window VARCHAR(16) DEFAULT NULL,
    query_count INT DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX idx_closure_rebuild_run_started ON closure_rebuild_run (started_at)');
        $this->addSql(<<<'SQL'
INSERT INTO closure_rebuild_run (id, triggered_by, status, full_rebuild, hospital_ids, started_at, finished_at, duration_ms, passes, events, partitions, hours_written, publish_ms, peak_memory_bytes, request_count, error_class, error_message, reference_window, query_count)
SELECT id, triggered_by, status,
       COALESCE((metrics->>'full')::boolean, false),
       COALESCE(metrics->'hospital_ids', '[]'::jsonb)::json,
       started_at AT TIME ZONE 'Europe/Berlin',
       finished_at AT TIME ZONE 'Europe/Berlin',
       duration_ms,
       COALESCE((metrics->>'passes')::int, 0),
       COALESCE((metrics->>'events')::int, 0),
       COALESCE((metrics->>'partitions')::int, 0),
       COALESCE((metrics->>'hours_written')::bigint, 0),
       (metrics->>'publish_ms')::int,
       peak_memory_bytes,
       COALESCE((metrics->>'requests')::int, 0),
       error_class, error_message,
       metrics->>'reference_window',
       (metrics->>'queries')::int
FROM job_run
WHERE job = 'closure_rebuild'
SQL);
        $this->addSql('DROP TABLE job_run');
    }
}
