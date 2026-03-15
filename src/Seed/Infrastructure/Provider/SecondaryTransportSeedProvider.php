<?php

namespace App\Seed\Infrastructure\Provider;

use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Seed\Application\Contracts\SeedProviderInterface;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<string>
 */
#[AsTaggedItem('app.seed_provider')]
final class SecondaryTransportSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<SecondaryTransport>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        foreach ($this->provide() as $name) {
            $entity = new SecondaryTransport()
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
        yield 'Diagnostik';
        yield 'Intensivstation';
        yield 'Intervention';
        yield 'OP';
        yield 'Sekundärverlegung';
        yield 'Sonstiger Einsatz';
        yield 'Weaning';
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['secondary_transport'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'secondary_transport';
    }
}
