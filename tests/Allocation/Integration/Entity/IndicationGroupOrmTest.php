<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Entity;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationGroupOrmTest extends KernelTestCase
{
    use Factories;

    public function testPersistsManyToManyRelation(): void
    {
        self::bootKernel();

        $group = IndicationGroupFactory::createOne(['name' => 'Cardiology']);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'STEMI']);

        $group->_real()->addIndication($indication->_real());
        $group->_save();

        $ids = self::getContainer()->get(IndicationGroupRepository::class)->getIndicationIds($group->getId());

        self::assertSame([$indication->getId()], $ids);
        self::assertInstanceOf(IndicationGroup::class, IndicationGroupFactory::repository()->find($group->getId()));
    }
}
