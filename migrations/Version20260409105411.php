<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260409105411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove deprecated legacy aggregation tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_age_buckets');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_cohort_stats');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_cohort_sums');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_counts');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_hourly');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_top_categories');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_transport_time_buckets');
        $this->addSql('DROP TABLE IF EXISTS agg_allocations_transport_time_dim');
        $this->addSql('DROP TABLE IF EXISTS agg_scope');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('This migration cannot be reverted automatically.');
    }
}
