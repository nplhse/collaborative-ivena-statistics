<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Demo;

/**
 * Decides whether a database may serve the demo environment (ADR 023): it must be on this machine
 * and its name must end in "_demo", so the demo never writes into the dev database or a server.
 */
final class DemoDatabaseTarget
{
    public const string NAME_SUFFIX = '_demo';

    /** Loopback addresses and the database service of compose.yaml. */
    public const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]', 'database'];

    /**
     * @param array<string, mixed> $connectionParams connection parameters, e.g. from DBAL's DsnParser
     *
     * @return list<string> reasons the target is refused; empty when it is safe
     */
    public static function violations(array $connectionParams): array
    {
        $violations = [];

        $host = $connectionParams['host'] ?? null;
        // No host means a Unix socket, which is always local.
        if (\is_string($host) && '' !== $host && !\in_array(strtolower($host), self::LOCAL_HOSTS, true)) {
            $violations[] = \sprintf('database host "%s" is not local (allowed: %s)', $host, implode(', ', self::LOCAL_HOSTS));
        }

        $name = $connectionParams['dbname'] ?? null;
        if (!\is_string($name) || !str_ends_with($name, self::NAME_SUFFIX)) {
            $violations[] = \sprintf('database name "%s" does not end in "%s"', \is_string($name) ? $name : '', self::NAME_SUFFIX);
        }

        return $violations;
    }
}
