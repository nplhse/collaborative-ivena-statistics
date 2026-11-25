<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A hospital assignment request can carry the requester's confirmation of the upload authority (#1565): time and terms
 * version, stored as the upload authority when the request is approved with the import permission.
 */
final class Version20261125081805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the upload authority confirmation on hospital assignment requests.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hospital_assignment_request ADD upload_authority_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE hospital_assignment_request ADD upload_authority_terms_version VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hospital_assignment_request DROP upload_authority_confirmed_at');
        $this->addSql('ALTER TABLE hospital_assignment_request DROP upload_authority_terms_version');
    }
}
