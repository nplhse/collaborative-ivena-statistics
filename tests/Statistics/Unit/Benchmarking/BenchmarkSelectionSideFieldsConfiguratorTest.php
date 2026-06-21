<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionSideType;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BenchmarkSelectionSideFieldsConfiguratorTest extends KernelTestCase
{
    use EligibleBenchmarkScopeTrait;
    use Factories;

    public function testStateScopeDetailDefaultIsStringWhenChoicesUseNumericKeys(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->seedEligibleBenchmarkScope($user, 'ConfiguratorNumericKey');
        $this->loginUser($user);

        $formFactory = self::getContainer()->get(FormFactoryInterface::class);
        $form = $formFactory->create(BenchmarkSelectionSideType::class, new BenchmarkSelectionSideFormData(
            'state',
            null,
            'all',
        ), [
            'side' => StatisticsFilterSide::Primary,
            'locale' => 'en',
        ]);

        self::assertTrue($form->has('scopeDetail'));
        self::assertIsString($form->get('scopeDetail')->getConfig()->getData());
    }

    private function loginUser(\App\User\Domain\Entity\User $user): void
    {
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
