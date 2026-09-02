<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902094514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hospital.participating_since for the participating-hospitals KPI history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hospital ADD participating_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hospital DROP participating_since');
    }
}
