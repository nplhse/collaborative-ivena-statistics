<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251101173523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION period_month(ts timestamp)
RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT date_trunc('month', ts AT TIME ZONE 'Europe/Berlin')::date;
$$;
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION IF EXISTS period_month(timestamp);');
    }
}
