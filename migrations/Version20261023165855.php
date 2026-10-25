<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user.first_import names the import and links to it, so it is scoped to the import's hospital (ADR 016).
 * Stored entries take the hospital of their linked import; entries whose import is gone keep the hospital
 * scope without a reference and are shown redacted.
 */
final class Version20261023165855 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope stored first-import notifications to the hospital of their import (ADR 016)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE notification n
            SET access_scope = 'hospital', scope_reference_id = i.hospital_id
            FROM import i
            WHERE n.type = 'user.first_import'
              AND n.access_scope = 'none'
              AND n.action_route = 'app_import_show'
              AND i.id = CAST(n.action_parameters::jsonb ->> 'id' AS INTEGER)
            SQL);
        $this->addSql("UPDATE notification SET access_scope = 'hospital', scope_reference_id = NULL WHERE type = 'user.first_import' AND access_scope = 'none'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE notification SET access_scope = 'none', scope_reference_id = NULL WHERE type = 'user.first_import'");
    }
}
