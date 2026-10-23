<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022133335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the required role of role-scoped notifications (ADR 016, step 2).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD required_role VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP required_role');
    }
}
