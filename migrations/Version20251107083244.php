<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251107083244 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert agg_allocations_hourly.hours_count from integer[] to jsonb';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE agg_allocations_hourly ADD COLUMN hours_count_json jsonb DEFAULT '{}'::jsonb");

        $this->addSql("
            UPDATE agg_allocations_hourly
               SET hours_count_json = jsonb_build_object('total', hours_count)
        ");

        $this->addSql('ALTER TABLE agg_allocations_hourly DROP COLUMN hours_count');
        $this->addSql('ALTER TABLE agg_allocations_hourly RENAME COLUMN hours_count_json TO hours_count');
        $this->addSql("ALTER TABLE agg_allocations_hourly ALTER COLUMN hours_count SET DEFAULT '{}'::jsonb");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agg_allocations_hourly ADD COLUMN hours_count_int int[]');

        $this->addSql("
            UPDATE agg_allocations_hourly
               SET hours_count_int = (
                 SELECT jsonb_array_elements_text(hours_count->'total')::int[]
               )
        ");

        $this->addSql('ALTER TABLE agg_allocations_hourly DROP COLUMN hours_count');
        $this->addSql('ALTER TABLE agg_allocations_hourly RENAME COLUMN hours_count_int TO hours_count');
    }
}
