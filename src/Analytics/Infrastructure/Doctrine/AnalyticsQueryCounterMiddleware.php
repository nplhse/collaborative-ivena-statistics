<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * @psalm-suppress UnusedClass Wired via doctrine.middleware tag in services.yaml.
 */
final readonly class AnalyticsQueryCounterMiddleware implements Middleware
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsQueryCounter $counter,
    ) {
    }

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->counter) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly AnalyticsQueryCounter $counter,
            ) {
                parent::__construct($driver);
            }

            #[\Override]
            public function connect(array $params): Connection
            {
                return new AnalyticsCountingConnection(parent::connect($params), $this->counter);
            }
        };
    }
}

/**
 * @internal
 */
final readonly class AnalyticsCountingConnection implements Connection
{
    public function __construct(
        private Connection $connection,
        private AnalyticsQueryCounter $counter,
    ) {
    }

    #[\Override]
    public function prepare(string $sql): Statement
    {
        return new AnalyticsCountingStatement($this->connection->prepare($sql), $this->counter);
    }

    #[\Override]
    public function query(string $sql): Result
    {
        $start = (float) hrtime(true);
        try {
            return $this->connection->query($sql);
        } finally {
            $this->counter->record(((float) hrtime(true) - $start) / 1_000_000.0);
        }
    }

    #[\Override]
    public function quote(string $value): string
    {
        return $this->connection->quote($value);
    }

    #[\Override]
    public function exec(string $sql): int|string
    {
        $start = (float) hrtime(true);
        try {
            return $this->connection->exec($sql);
        } finally {
            $this->counter->record(((float) hrtime(true) - $start) / 1_000_000.0);
        }
    }

    #[\Override]
    public function lastInsertId(): int|string
    {
        return $this->connection->lastInsertId();
    }

    #[\Override]
    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    #[\Override]
    public function commit(): void
    {
        $this->connection->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    #[\Override]
    public function getServerVersion(): string
    {
        return $this->connection->getServerVersion();
    }

    #[\Override]
    public function getNativeConnection()
    {
        return $this->connection->getNativeConnection();
    }
}

/**
 * @internal
 */
final readonly class AnalyticsCountingStatement implements Statement
{
    public function __construct(
        private Statement $statement,
        private AnalyticsQueryCounter $counter,
    ) {
    }

    #[\Override]
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->statement->bindValue($param, $value, $type);
    }

    #[\Override]
    public function execute(): Result
    {
        $start = (float) hrtime(true);
        try {
            return $this->statement->execute();
        } finally {
            $this->counter->record(((float) hrtime(true) - $start) / 1_000_000.0);
        }
    }
}
