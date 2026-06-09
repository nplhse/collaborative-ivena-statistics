<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608195024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add latitude and longitude columns to hospital for population map visualisation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hospital ADD latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE hospital ADD longitude DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hospital DROP latitude');
        $this->addSql('ALTER TABLE hospital DROP longitude');
    }
}
