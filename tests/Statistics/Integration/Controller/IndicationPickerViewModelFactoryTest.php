<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Controller;

use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Statistics\UI\Http\Controller\IndicationPickerViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationPickerViewModelFactoryTest extends KernelTestCase
{
    use Factories;

    public function testCreatesMenuItemsAndSelectedLabel(): void
    {
        self::bootKernel();

        IndicationNormalizedFactory::createOne(['name' => 'Picker Alpha']);
        $second = IndicationNormalizedFactory::createOne(['name' => 'Picker Beta']);

        $factory = self::getContainer()->get(IndicationPickerViewModelFactory::class);
        $viewModel = $factory->create(
            Request::create('/statistics/indication-insights?scope=public&period=all'),
            $second->getId(),
        );

        self::assertStringContainsString('Picker Beta', $viewModel->selectedLabel);
        self::assertGreaterThanOrEqual(2, \count($viewModel->menuItems));

        $activeItems = array_values(array_filter(
            $viewModel->menuItems,
            static fn (array $item): bool => true === $item['active'],
        ));
        self::assertCount(1, $activeItems);
        self::assertSame($second->getId(), $activeItems[0]['id']);
        self::assertStringContainsString('/statistics/indication/'.$second->getId(), $activeItems[0]['url']);
    }

    public function testFallsBackToRepositoryLabelWhenItemNotInDatalist(): void
    {
        self::bootKernel();

        $indication = IndicationNormalizedFactory::createOne(['name' => 'Picker Fallback']);

        $factory = self::getContainer()->get(IndicationPickerViewModelFactory::class);
        $viewModel = $factory->create(
            Request::create('/statistics/indication/'.$indication->getId()),
            $indication->getId(),
        );

        self::assertStringContainsString('Picker Fallback', $viewModel->selectedLabel);
    }
}
