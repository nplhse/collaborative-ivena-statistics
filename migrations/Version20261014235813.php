<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014235813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which closure rebuild run claimed a request and park requests that failed repeatedly.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE closure_rebuild_request ADD claimed_by VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE closure_rebuild_request ADD failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE closure_rebuild_request ADD last_error TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_closure_rebuild_request_pending ON closure_rebuild_request (kind) WHERE consumed_at IS NULL AND failed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_closure_rebuild_request_pending');
        $this->addSql('ALTER TABLE closure_rebuild_request DROP last_error');
        $this->addSql('ALTER TABLE closure_rebuild_request DROP failed_at');
        $this->addSql('ALTER TABLE closure_rebuild_request DROP claimed_by');
    }
}
