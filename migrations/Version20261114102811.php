<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Usage analytics no longer measures request performance (#951); Sentry covers it. Drops the timing and query
 * columns of the raw requests and the error count and performance sums of the daily aggregates, together with
 * their stored history. `http_status` stays on the raw rows for the counted-access classification.
 *
 * `down()` restores the columns with their previous types. The values are gone, so existing rows get 0.
 */
final class Version20261114102811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop request performance columns from analytics_request and analytics_request_daily (#951)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analytics_request DROP COLUMN duration_ms, DROP COLUMN db_query_count, DROP COLUMN db_time_ms');
        $this->addSql('ALTER TABLE analytics_request_daily DROP COLUMN error_count, DROP COLUMN sum_duration_ms, DROP COLUMN sum_db_query_count, DROP COLUMN sum_db_time_ms');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analytics_request ADD COLUMN duration_ms INT DEFAULT 0 NOT NULL, ADD COLUMN db_query_count INT DEFAULT 0 NOT NULL, ADD COLUMN db_time_ms INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE analytics_request ALTER COLUMN duration_ms DROP DEFAULT, ALTER COLUMN db_query_count DROP DEFAULT, ALTER COLUMN db_time_ms DROP DEFAULT');
        $this->addSql('ALTER TABLE analytics_request_daily ADD COLUMN error_count INT DEFAULT 0 NOT NULL, ADD COLUMN sum_duration_ms INT DEFAULT 0 NOT NULL, ADD COLUMN sum_db_query_count INT DEFAULT 0 NOT NULL, ADD COLUMN sum_db_time_ms INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE analytics_request_daily ALTER COLUMN error_count DROP DEFAULT, ALTER COLUMN sum_duration_ms DROP DEFAULT, ALTER COLUMN sum_db_query_count DROP DEFAULT, ALTER COLUMN sum_db_time_ms DROP DEFAULT');
    }
}
