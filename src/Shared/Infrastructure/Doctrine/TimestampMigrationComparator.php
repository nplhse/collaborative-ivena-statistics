<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * Orders migrations by the timestamp in their class name instead of the full name (ADR 037), so that the
 * migrations of a bundle in another namespace run after the core migrations they build on.
 *
 * Every migration must be named `…\VersionYYYYMMDDHHMMSS`. The sort key is (timestamp, full class name) for
 * all of them: equal timestamps are ordered by the full name (`strcmp`), so the order is total and, for the
 * core migrations of one namespace, the same as before. A name without that timestamp is refused instead of
 * being compared differently, which could make the order inconsistent for mixed names.
 *
 * @psalm-suppress UnusedClass Registered as the migrations comparator in config/packages/doctrine_migrations.yaml.
 */
final readonly class TimestampMigrationComparator implements Comparator
{
    private const string PATTERN = '/\\\\Version(\d{14})$/';

    #[\Override]
    public function compare(Version $a, Version $b): int
    {
        return [$this->timestamp($a), (string) $a] <=> [$this->timestamp($b), (string) $b];
    }

    private function timestamp(Version $version): string
    {
        if (1 !== preg_match(self::PATTERN, (string) $version, $match)) {
            throw new \LogicException(sprintf('Migration "%s" must be named …\Version<YYYYMMDDHHMMSS>.', (string) $version));
        }

        return $match[1];
    }
}
