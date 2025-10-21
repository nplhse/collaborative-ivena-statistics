<?php

namespace App\Service\Seed;

use App\Entity\Assignment;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<string>
 */
#[AsTaggedItem('app.seed_provider')]
final class AssignmentSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<Assignment>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        foreach ($this->provide() as $name) {
            $entity = new Assignment()
                ->setName($name)
                ->setCreatedBy($user);

            yield $entity;
        }
    }

    /**
     * @return iterable<string>
     */
    #[\Override]
    public function provide(): iterable
    {
        yield 'Arzt/Arzt';
        yield 'Einweisung';
        yield 'LST';
        yield 'Notzuweisung';
        yield 'Patient';
        yield 'RD';
        yield 'ZLST';
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['assignment'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'assignment';
    }
}
