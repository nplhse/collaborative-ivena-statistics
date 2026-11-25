<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conditions of a data extract release and their acceptance, and how a release ended: expired after three months or
 * revoked with a reason (#1635, ADR 045).
 */
final class Version20261125220550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conditions, their acceptance and the end of a release to data_request_release.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_request_release ADD conditions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_request_release ADD conditions_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE data_request_release ADD conditions_accepted_checksum VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE data_request_release ADD ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE data_request_release ADD revocation_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_request_release DROP conditions');
        $this->addSql('ALTER TABLE data_request_release DROP conditions_accepted_at');
        $this->addSql('ALTER TABLE data_request_release DROP conditions_accepted_checksum');
        $this->addSql('ALTER TABLE data_request_release DROP ended_at');
        $this->addSql('ALTER TABLE data_request_release DROP revocation_reason');
    }
}
