<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014235040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track claimed closure rebuild requests until their rebuild is published and store request times in Europe/Berlin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE closure_rebuild_request ADD claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE closure_rebuild_request ADD claim_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE closure_rebuild_request SET claim_attempts = 1 WHERE consumed_at IS NOT NULL');
        // consumed_at was written with CURRENT_TIMESTAMP in the database session time zone; requested_at already came from PHP.
        $this->addSql("UPDATE closure_rebuild_request SET consumed_at = (consumed_at AT TIME ZONE current_setting('TimeZone')) AT TIME ZONE 'Europe/Berlin' WHERE consumed_at IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE closure_rebuild_request SET consumed_at = (consumed_at AT TIME ZONE 'Europe/Berlin') AT TIME ZONE current_setting('TimeZone') WHERE consumed_at IS NOT NULL");
        $this->addSql('ALTER TABLE closure_rebuild_request DROP claim_attempts');
        $this->addSql('ALTER TABLE closure_rebuild_request DROP claimed_at');
    }
}
