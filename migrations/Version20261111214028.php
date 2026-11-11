<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saved explorer views stored the period identifier `all` for the rolling 12-month window (#1017).
 * That window is now `last_12_months`; `all` is reserved for the whole history from v0.4-beta on.
 * Covers the current state (query.period.type) and the legacy flat state (period).
 */
final class Version20261111214028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the saved explorer view period "all" to "last_12_months" (#1017)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{query,period,type}', '"last_12_months"')::json
            WHERE config_json::jsonb #>> '{query,period,type}' = 'all'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{period}', '"last_12_months"')::json
            WHERE config_json::jsonb ->> 'period' = 'all'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{query,period,type}', '"all"')::json
            WHERE config_json::jsonb #>> '{query,period,type}' = 'last_12_months'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE saved_explorer_view
            SET config_json = jsonb_set(config_json::jsonb, '{period}', '"all"')::json
            WHERE config_json::jsonb ->> 'period' = 'last_12_months'
            SQL);
    }
}
