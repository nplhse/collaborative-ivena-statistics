<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025143848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Closure rebuild requests carry the changed time range (ADR 015 phase 4); NULL means the whole hospital.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE closure_rebuild_request ADD range_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE closure_rebuild_request ADD range_to TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE closure_rebuild_request DROP range_from');
        $this->addSql('ALTER TABLE closure_rebuild_request DROP range_to');
    }
}
