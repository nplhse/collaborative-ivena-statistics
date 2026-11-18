<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Closure Analytics and closure imports left the beta (#1350): the opt-in role ROLE_CLOSURE_BETA no longer
 * exists, so it is removed from the stored user roles. Otherwise the role field in EasyAdmin would meet a
 * value that is not among its choices.
 */
final class Version20261118092325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the retired role ROLE_CLOSURE_BETA from all users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                UPDATE "user"
                SET roles = (roles::jsonb - 'ROLE_CLOSURE_BETA')::json
                WHERE jsonb_exists(roles::jsonb, 'ROLE_CLOSURE_BETA')
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore: the role has no meaning any more, and who held it is not recorded.
    }
}
