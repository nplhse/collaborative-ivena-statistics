<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529130837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove user_id from import_batch_run; audit user comes from Import.createdBy per dispatch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch_run DROP COLUMN user_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch_run ADD user_id INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE import_batch_run ALTER COLUMN user_id DROP DEFAULT');
    }
}
