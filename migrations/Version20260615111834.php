<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615111834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hospital_access_grant table for clinic-specific user permissions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE hospital_access_grant (id SERIAL NOT NULL, hospital_id INT NOT NULL, user_id INT NOT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, permissions INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_HOSPITAL_ACCESS_GRANT_HOSPITAL ON hospital_access_grant (hospital_id)');
        $this->addSql('CREATE INDEX IDX_HOSPITAL_ACCESS_GRANT_USER ON hospital_access_grant (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_hospital_access_grant_hospital_user ON hospital_access_grant (hospital_id, user_id)');
        $this->addSql('ALTER TABLE hospital_access_grant ADD CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_HOSPITAL FOREIGN KEY (hospital_id) REFERENCES hospital (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE hospital_access_grant ADD CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE hospital_access_grant ADD CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE hospital_access_grant ADD CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_UPDATED_BY FOREIGN KEY (updated_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hospital_access_grant DROP CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_HOSPITAL');
        $this->addSql('ALTER TABLE hospital_access_grant DROP CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_USER');
        $this->addSql('ALTER TABLE hospital_access_grant DROP CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_CREATED_BY');
        $this->addSql('ALTER TABLE hospital_access_grant DROP CONSTRAINT FK_HOSPITAL_ACCESS_GRANT_UPDATED_BY');
        $this->addSql('DROP TABLE hospital_access_grant');
    }
}
