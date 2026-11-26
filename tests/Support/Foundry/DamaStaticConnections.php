<?php

declare(strict_types=1);

namespace App\Tests\Support\Foundry;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;

/**
 * Drops the database connections that DAMA keeps open across tests.
 *
 * Foundry's migrate reset terminates every other backend of the test database
 * (`pg_terminate_backend()` in `BaseOrmResetter::dropAndResetDatabase()`), including a
 * connection DAMA opened for an earlier test class without `#[ResetDatabase]`. DAMA keeps
 * that dead connection in `StaticDriver::$connections` and would reuse it for every later
 * test (#887). Forgetting it makes the next test open a fresh connection.
 *
 * DAMA (checked with 8.7) has no public API for this, so the private static property is
 * cleared via reflection. If a DAMA upgrade renames or removes it, this fails loudly instead
 * of silently leaving the stale connection in place.
 */
final class DamaStaticConnections
{
    private const string PROPERTY = 'connections';

    public static function forgetAll(): void
    {
        if (!property_exists(StaticDriver::class, self::PROPERTY)) {
            throw new \LogicException(sprintf('%s::$%s no longer exists, so stale DAMA connections cannot be dropped after a database reset (#887). Check whether the installed DAMA version offers a public way to reset static connections and update %s.', StaticDriver::class, self::PROPERTY, self::class));
        }

        $property = new \ReflectionProperty(StaticDriver::class, self::PROPERTY);

        if (!$property->isStatic() || !\is_array($property->getValue())) {
            throw new \LogicException(sprintf('%s::$%s is no longer a static array of connections; update %s for the installed DAMA version (#887).', StaticDriver::class, self::PROPERTY, self::class));
        }

        $property->setValue(null, []);
    }
}
