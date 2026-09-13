<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionType;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BenchmarkSelectionTypeTest extends KernelTestCase
{
    use EligibleBenchmarkScopeTrait;
    use Factories;

    public function testSubmitIgnoresRootExtraFieldsAndNestedFormNameWrapper(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->seedEligibleBenchmarkScope($user, 'RootTypeExtra');

        $formFactory = self::getContainer()->get(FormFactoryInterface::class);
        $form = $formFactory->create(BenchmarkSelectionType::class, new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('public', null, 'all'),
            new BenchmarkSelectionSideFormData('public', null, 'all_time'),
        ), [
            'locale' => 'en',
        ]);

        $form->submit([
            'primary' => [
                'scopeGroup' => 'public',
                'period' => 'all',
            ],
            'comparison' => [
                'scopeGroup' => 'public',
                'period' => 'all_time',
            ],
            'scopeDetail' => 'leftover',
            'periodYear' => '2025',
            'benchmark_selection' => [
                'primary' => [
                    'scopeGroup' => 'state',
                    'period' => 'year',
                ],
            ],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        $data = $form->getData();
        self::assertInstanceOf(BenchmarkSelectionFormData::class, $data);
        self::assertSame('public', $data->primary->scopeGroup);
        self::assertSame('all', $data->primary->period);
        self::assertSame('public', $data->comparison->scopeGroup);
        self::assertSame('all_time', $data->comparison->period);
    }
}
