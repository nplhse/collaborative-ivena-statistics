<?php

declare(strict_types=1);

namespace App\Tests\User\Doubles;

use App\User\Application\Contract\LoginFailureRecorderInterface;
use App\User\Domain\Enum\LoginFailureKind;

/**
 * Keeps recorded failures in memory, with the identifier normalised like LoginFailureRecorder does before hashing.
 * LoginFailureRecorderContract proves that both behave alike.
 */
final class InMemoryLoginFailureRecorder implements LoginFailureRecorderInterface
{
    /** @var list<array{kind: LoginFailureKind, identifier: ?string, ip: ?string}> */
    public array $records = [];

    #[\Override]
    public function record(LoginFailureKind $kind, ?string $identifier, ?string $ip): void
    {
        $identifier = null !== $identifier ? mb_strtolower(trim($identifier)) : null;

        $this->records[] = ['kind' => $kind, 'identifier' => '' !== $identifier ? $identifier : null, 'ip' => '' !== $ip ? $ip : null];
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_map(static fn (array $record): string => $record['kind']->value, $this->records);
    }
}
