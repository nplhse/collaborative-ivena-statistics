<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Since v0.4-beta the period identifier of the whole history is `all` instead of `all_time` (#1035).
 * Saved explorer views are rewritten; `all` is not stored by any view, because Version20261111214028
 * renamed the rolling 12-month `all` to `last_12_months` and v0.3 did not accept `all`.
 * Covers the current state (query.period.type) and the legacy flat state (period).
 */
final class Version20261127070034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the saved explorer view period "all_time" to "all" (#1035)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{query,period,type}', '"all"')::json
            WHERE config_json::jsonb #>> '{query,period,type}' = 'all_time'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{period}', '"all"')::json
            WHERE config_json::jsonb ->> 'period' = 'all_time'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{query,period,type}', '"all_time"')::json
            WHERE config_json::jsonb #>> '{query,period,type}' = 'all'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{period}', '"all_time"')::json
            WHERE config_json::jsonb ->> 'period' = 'all'
            SQL);
    }
}
