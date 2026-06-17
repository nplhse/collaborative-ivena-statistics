<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617131245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indication_group and many-to-many relation to indication_normalized';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE indication_group (id SERIAL NOT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, category VARCHAR(120) DEFAULT NULL, sort_order INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_INDICATION_GROUP_CREATED_BY ON indication_group (created_by_id)');
        $this->addSql('CREATE INDEX IDX_INDICATION_GROUP_UPDATED_BY ON indication_group (updated_by_id)');
        $this->addSql('CREATE TABLE indication_group_indication_normalized (indication_group_id INT NOT NULL, indication_normalized_id INT NOT NULL, PRIMARY KEY(indication_group_id, indication_normalized_id))');
        $this->addSql('CREATE INDEX IDX_IGIN_GROUP ON indication_group_indication_normalized (indication_group_id)');
        $this->addSql('CREATE INDEX IDX_IGIN_INDICATION ON indication_group_indication_normalized (indication_normalized_id)');
        $this->addSql('ALTER TABLE indication_group ADD CONSTRAINT FK_INDICATION_GROUP_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE indication_group ADD CONSTRAINT FK_INDICATION_GROUP_UPDATED_BY FOREIGN KEY (updated_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE indication_group_indication_normalized ADD CONSTRAINT FK_IGIN_GROUP FOREIGN KEY (indication_group_id) REFERENCES indication_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE indication_group_indication_normalized ADD CONSTRAINT FK_IGIN_INDICATION FOREIGN KEY (indication_normalized_id) REFERENCES indication_normalized (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE indication_group_indication_normalized DROP CONSTRAINT FK_IGIN_GROUP');
        $this->addSql('ALTER TABLE indication_group_indication_normalized DROP CONSTRAINT FK_IGIN_INDICATION');
        $this->addSql('ALTER TABLE indication_group DROP CONSTRAINT FK_INDICATION_GROUP_CREATED_BY');
        $this->addSql('ALTER TABLE indication_group DROP CONSTRAINT FK_INDICATION_GROUP_UPDATED_BY');
        $this->addSql('DROP TABLE indication_group_indication_normalized');
        $this->addSql('DROP TABLE indication_group');
    }
}
