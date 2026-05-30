<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529120514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_batch_run and import_batch_run_item tables for batch requeue checkpoints.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_batch_run (id SERIAL NOT NULL, status VARCHAR(255) NOT NULL, user_id INT NOT NULL, options JSON NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN import_batch_run.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN import_batch_run.finished_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN import_batch_run.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE import_batch_run_item (id SERIAL NOT NULL, run_id INT NOT NULL, import_id INT NOT NULL, import_name VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, attempt_count INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_import_batch_run_item_run_import ON import_batch_run_item (run_id, import_id)');
        $this->addSql('CREATE INDEX idx_import_batch_run_item_run_status ON import_batch_run_item (run_id, status)');
        $this->addSql('COMMENT ON COLUMN import_batch_run_item.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN import_batch_run_item.finished_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE import_batch_run_item ADD CONSTRAINT FK_IMPORT_BATCH_RUN_ITEM_RUN FOREIGN KEY (run_id) REFERENCES import_batch_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch_run_item DROP CONSTRAINT FK_IMPORT_BATCH_RUN_ITEM_RUN');
        $this->addSql('DROP TABLE import_batch_run_item');
        $this->addSql('DROP TABLE import_batch_run');
    }
}
