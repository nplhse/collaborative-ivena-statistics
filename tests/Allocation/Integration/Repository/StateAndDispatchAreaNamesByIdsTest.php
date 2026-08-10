<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Repository;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class StateAndDispatchAreaNamesByIdsTest extends KernelTestCase
{
    use Factories;

    public function testStateFindNamesByIdsReturnsIdToNameMap(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(StateRepository::class);
        $state = StateFactory::createOne(['name' => 'Lookup State']);

        self::assertSame(
            [(int) $state->getId() => 'Lookup State'],
            $repo->findNamesByIds([(int) $state->getId()]),
        );
        self::assertSame([], $repo->findNamesByIds([]));
    }

    public function testDispatchAreaFindNamesByIdsReturnsIdToNameMap(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(DispatchAreaRepository::class);
        $state = StateFactory::createOne();
        $area = DispatchAreaFactory::createOne(['name' => 'Lookup Area', 'state' => $state]);

        self::assertSame(
            [(int) $area->getId() => 'Lookup Area'],
            $repo->findNamesByIds([(int) $area->getId()]),
        );
        self::assertSame([], $repo->findNamesByIds([]));
    }
}
