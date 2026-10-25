<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025132629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the SQL statements of each closure rebuild run (ADR 015 phase 3 acceptance).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE closure_rebuild_run ADD query_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE closure_rebuild_run DROP query_count');
    }
}
