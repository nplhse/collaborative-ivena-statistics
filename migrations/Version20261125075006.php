<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Upload authority per hospital (#1565): an acceptance of kind `upload_authority` names its hospital. Existing rows
 * are acceptances of the terms of use and keep no hospital; nothing is backfilled.
 */
final class Version20261125075006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the hospital of an upload authority to terms acceptances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE terms_acceptance ADD hospital_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE terms_acceptance ADD CONSTRAINT FK_5937E8963DBB69 FOREIGN KEY (hospital_id) REFERENCES hospital (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_terms_acceptance_user_hospital ON terms_acceptance (user_id, hospital_id, kind)');
        $this->addSql('CREATE INDEX IDX_5937E8963DBB69 ON terms_acceptance (hospital_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE terms_acceptance DROP CONSTRAINT FK_5937E8963DBB69');
        $this->addSql('DROP INDEX idx_terms_acceptance_user_hospital');
        $this->addSql('DROP INDEX IDX_5937E8963DBB69');
        $this->addSql('ALTER TABLE terms_acceptance DROP hospital_id');
    }
}
