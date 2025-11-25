<?php

namespace App\Seed\Application\Contracts;

use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * @template TValue
 */
#[AutoconfigureTag('app.seed_provider')]
interface SeedProviderInterface
{
    /**
     * @return iterable<object>
     */
    public function build(User $user): iterable;

    /**
     * @return iterable<TValue>
     */
    public function provide(): iterable;

    /**
     * @return list<string>
     */
    public function purgeTables(): array;

    /** @return non-empty-string */
    public function getType(): string;
}
