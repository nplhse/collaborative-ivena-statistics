<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reference rows are identified by their public id in catalog packages (issue #740, ADR 020).
 */
final class Version20261026215253 extends AbstractMigration
{
    /** @var list<string> */
    private const array TABLES = [
        'state',
        'dispatch_area',
        'department',
        'speciality',
        'assignment',
        'occasion',
        'infection',
        'secondary_transport',
        'indication_normalized',
        'indication_raw',
        'indication_group',
        'hospital',
    ];

    public function getDescription(): string
    {
        return 'Fill missing public ids of the reference tables and make the column required.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('UPDATE %s SET public_id = gen_random_uuid()::text WHERE public_id IS NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN public_id SET NOT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN public_id DROP NOT NULL', $table));
        }
    }
}
