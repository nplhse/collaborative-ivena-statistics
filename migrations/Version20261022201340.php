<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022201340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Batch re-imports: dispatch and processing end of import_batch_run; items mirror the outcome of their run (ADR 016, step 4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch_run ADD dispatch_closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE import_batch_run ADD processing_finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Items of earlier batches take over the outcome their run already reached.
        $this->addSql(<<<'SQL'
            UPDATE import_batch_run_item item
            SET status = run.status, finished_at = run.finished_at
            FROM import_run run
            WHERE item.import_run_id = run.id
              AND item.status = 'queued'
              AND run.status IN ('completed', 'partial', 'failed', 'superseded')
            SQL);

        // Earlier batches count as summarized: nobody gets a summary for them after the deploy.
        $this->addSql("UPDATE import_batch_run SET dispatch_closed_at = finished_at, processing_finished_at = finished_at WHERE status = 'finished'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE import_batch_run_item SET status = 'queued' WHERE status IN ('completed', 'partial', 'failed')");
        $this->addSql('ALTER TABLE import_batch_run DROP dispatch_closed_at');
        $this->addSql('ALTER TABLE import_batch_run DROP processing_finished_at');
    }
}
