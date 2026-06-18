<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Controller;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Statistics\UI\Http\Controller\IndicationCompareSubjectPickerViewModelFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationCompareSubjectPickerViewModelFactoryTest extends KernelTestCase
{
    use Factories;

    public function testBuildMenuItemsIncludesGroupsAndIndicationsWithType(): void
    {
        self::bootKernel();

        $user = UserFactory::createOne(['username' => 'compare-picker-'.bin2hex(random_bytes(4))]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'Picker Indication', 'code' => 7101]);
        $group = IndicationGroupFactory::createOne(['name' => 'Picker Group', 'createdBy' => $user]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();

        $items = self::getContainer()->get(IndicationCompareSubjectPickerViewModelFactory::class)->buildMenuItems();

        $groupItem = null;
        $indicationItem = null;
        foreach ($items as $item) {
            if ('group' === $item['type'] && $item['id'] === $group->getId()) {
                $groupItem = $item;
            }
            if ('single' === $item['type'] && $item['id'] === $indication->getId()) {
                $indicationItem = $item;
            }
        }

        self::assertNotNull($groupItem);
        self::assertSame('Picker Group [Group]', $groupItem['label']);
        self::assertNotNull($indicationItem);
        self::assertStringContainsString('Picker Indication', $indicationItem['label']);
    }
}
