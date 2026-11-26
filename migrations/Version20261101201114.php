<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes stored onboarding analytics events (issue #950). The onboarding itself and its
 * persisted steps (user_onboarding_step) are not touched.
 */
final class Version20261101201114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete onboarding.step.completed and user.email_confirmed analytics events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM analytics_product_event WHERE event_name IN ('onboarding.step.completed', 'user.email_confirmed')");
        $this->addSql("DELETE FROM analytics_event_daily WHERE event_name IN ('onboarding.step.completed', 'user.email_confirmed')");
    }

    public function down(Schema $schema): void
    {
        // Deleted analytics events cannot be restored; nothing to undo in the schema.
    }
}
