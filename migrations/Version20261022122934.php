<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022122934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable import runs (ADR 016): import_run, import.current_run_id and the run reference of batch items.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE import_run (
    id UUID NOT NULL,
    status VARCHAR(16) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    rows_total INT DEFAULT NULL,
    rows_ok INT DEFAULT NULL,
    rows_rejected INT DEFAULT NULL,
    reason_code VARCHAR(64) DEFAULT NULL,
    import_id INT NOT NULL,
    initiated_by_id INT DEFAULT NULL,
    PRIMARY KEY (id)
)
SQL);
        $this->addSql('CREATE INDEX idx_import_run_status_finished ON import_run (status, finished_at)');
        $this->addSql('CREATE INDEX IDX_C41B0440B6A263D9 ON import_run (import_id)');
        $this->addSql('CREATE INDEX IDX_C41B0440C4EF1FC7 ON import_run (initiated_by_id)');
        $this->addSql('ALTER TABLE import_run ADD CONSTRAINT FK_C41B0440B6A263D9 FOREIGN KEY (import_id) REFERENCES import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE import_run ADD CONSTRAINT FK_C41B0440C4EF1FC7 FOREIGN KEY (initiated_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('ALTER TABLE import ADD current_run_id UUID DEFAULT NULL');

        $this->addSql('ALTER TABLE import_batch_run_item ADD import_run_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE import_batch_run_item ADD CONSTRAINT FK_D054F7ECF8D244DC FOREIGN KEY (import_run_id) REFERENCES import_run (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D054F7ECF8D244DC ON import_batch_run_item (import_run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch_run_item DROP CONSTRAINT FK_D054F7ECF8D244DC');
        $this->addSql('DROP INDEX UNIQ_D054F7ECF8D244DC');
        $this->addSql('ALTER TABLE import_batch_run_item DROP import_run_id');
        $this->addSql('ALTER TABLE import DROP current_run_id');
        $this->addSql('DROP TABLE import_run');
    }
}
