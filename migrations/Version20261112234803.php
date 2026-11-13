<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit log correlation for the audit timeline (issue #955, ADR 025).
 *
 * Existing rows become request_id_kind = 'legacy' through the column default. intent and outcome stay
 * empty here; app:audit:backfill-derived copies them from the stored metadata. session_ref stays empty
 * for existing rows on purpose: there is no evidence for it.
 */
final class Version20261112234803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log intent, outcome, request_id_kind, session_ref and timeline indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ADD intent VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_log ADD outcome VARCHAR(16) DEFAULT NULL');
        $this->addSql("ALTER TABLE audit_log ADD request_id_kind VARCHAR(16) DEFAULT 'legacy' NOT NULL");
        $this->addSql('ALTER TABLE audit_log ADD session_ref UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_audit_log_time ON audit_log (occurred_at, id)');
        $this->addSql('CREATE INDEX idx_audit_log_actor_time ON audit_log (actor_id, occurred_at, id)');
        $this->addSql('CREATE INDEX idx_audit_log_intent ON audit_log (intent)');
        $this->addSql('CREATE INDEX idx_audit_log_session ON audit_log (session_ref)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_log_session');
        $this->addSql('DROP INDEX idx_audit_log_intent');
        $this->addSql('DROP INDEX idx_audit_log_actor_time');
        $this->addSql('DROP INDEX idx_audit_log_time');
        $this->addSql('ALTER TABLE audit_log DROP session_ref');
        $this->addSql('ALTER TABLE audit_log DROP request_id_kind');
        $this->addSql('ALTER TABLE audit_log DROP outcome');
        $this->addSql('ALTER TABLE audit_log DROP intent');
    }
}
