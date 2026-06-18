<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

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
final class BenchmarkSelectionSideTypeTest extends KernelTestCase
{
    use EligibleBenchmarkScopeTrait;
    use Factories;

    private FormFactoryInterface $formFactory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testRendersStateScopeDetailAndQuarterFields(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $scope = $this->seedEligibleBenchmarkScope($user, 'SideTypeState');

        $form = $this->formFactory->create(BenchmarkSelectionSideType::class, new BenchmarkSelectionSideFormData(
            'state',
            (string) $scope['state']->getId(),
            'quarter',
            2025,
            2,
        ), [
            'side' => StatisticsFilterSide::Primary,
            'locale' => 'en',
        ]);

        self::assertTrue($form->has('scopeDetail'));
        self::assertTrue($form->has('periodYear'));
        self::assertTrue($form->has('periodQuarter'));
        self::assertFalse($form->has('periodMonth'));
    }

    public function testRendersHospitalScopeDetailAndMonthFields(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->seedEligibleBenchmarkScope($user, 'SideTypeHospital');
        $this->loginUser($user);

        $form = $this->formFactory->create(BenchmarkSelectionSideType::class, new BenchmarkSelectionSideFormData(
            'my_hospitals',
            null,
            'month',
            2025,
            null,
            6,
        ), [
            'side' => StatisticsFilterSide::Comparison,
            'locale' => 'en',
        ]);

        self::assertTrue($form->has('scopeDetail'));
        self::assertTrue($form->has('periodYear'));
        self::assertTrue($form->has('periodMonth'));
        self::assertFalse($form->has('periodQuarter'));
        self::assertSame('', $form->get('scopeDetail')->getConfig()->getData());
    }

    public function testPreSubmitAddsDefaultsForNewlyVisibleDynamicFields(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $scope = $this->seedEligibleBenchmarkScope($user, 'SideTypeSubmit');

        $form = $this->formFactory->create(BenchmarkSelectionSideType::class, new BenchmarkSelectionSideFormData(
            'public',
            null,
            'all_time',
        ), [
            'side' => StatisticsFilterSide::Primary,
            'locale' => 'en',
        ]);

        $form->submit([
            'scopeGroup' => 'state',
            'period' => 'quarter',
            'scopeDetail' => (string) $scope['state']->getId(),
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->has('periodYear'));
        self::assertTrue($form->has('periodQuarter'));
        self::assertNotNull($form->get('periodYear')->getData());
        self::assertNotNull($form->get('periodQuarter')->getData());
    }

    private function loginUser(\App\User\Domain\Entity\User $user): void
    {
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
