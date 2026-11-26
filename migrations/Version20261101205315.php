<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marks whether a tracked request counts as an access (page view, issue #950). Existing rows stay
 * NULL until `app:analytics:classify-accesses` backfills them.
 */
final class Version20261101205315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analytics_request.counted_access.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analytics_request ADD counted_access BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analytics_request DROP counted_access');
    }
}
