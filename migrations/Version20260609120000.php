<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image width and height columns to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD width INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD height INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP width');
        $this->addSql('ALTER TABLE media DROP height');
    }
}
