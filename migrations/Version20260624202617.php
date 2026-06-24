<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624202617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add secondary_transport_id and department_was_closed to allocation_stats_projection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation_stats_projection ADD COLUMN IF NOT EXISTS secondary_transport_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE allocation_stats_projection ADD COLUMN IF NOT EXISTS department_was_closed BOOLEAN DEFAULT NULL');

        $this->addSql(<<<'SQL'
UPDATE allocation_stats_projection p
SET
    secondary_transport_id = a.secondary_transport_id,
    department_was_closed = a.department_was_closed
FROM allocation a
WHERE a.id = p.id
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE allocation_stats_projection DROP COLUMN IF EXISTS department_was_closed');
        $this->addSql('ALTER TABLE allocation_stats_projection DROP COLUMN IF EXISTS secondary_transport_id');
    }
}
