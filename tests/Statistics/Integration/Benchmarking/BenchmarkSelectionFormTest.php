<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Benchmarking;

use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\Tests\Statistics\Support\Benchmarking\EligibleBenchmarkScopeTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BenchmarkSelectionFormTest extends WebTestCase
{
    use EligibleBenchmarkScopeTrait;
    use Factories;
    use InteractsWithLiveComponents;

    private ?KernelBrowser $client = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->client();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->client = null;
        parent::tearDown();
    }

    private function client(): KernelBrowser
    {
        return $this->client ??= self::createClient();
    }

    public function testPeriodChangeReRendersYearField(): void
    {
        $user = UserFactory::createOne(['username' => 'live-benchmark-'.bin2hex(random_bytes(4))]);

        $initialData = new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('public', null, 'all'),
            new BenchmarkSelectionSideFormData('public', null, 'all_time'),
        );

        $testComponent = $this->createLiveComponent('BenchmarkSelectionForm', [
            'initialData' => $initialData,
            'preservedQuery' => [],
            'locale' => 'en',
        ])->actingAs($user->_real());

        $initialRender = $testComponent->render();
        self::assertGreaterThan(0, $initialRender->crawler()->filter('[data-controller="live"]')->count());
        self::assertCount(0, $initialRender->crawler()->filter('[data-testid="stats-benchmark-primary-form"] select[name$="[periodYear]"]'));

        $formName = $initialRender->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $updatedRender = $testComponent
            ->submitForm([
                $formName => [
                    'primary' => [
                        'scopeGroup' => 'public',
                        'period' => 'year',
                    ],
                    'comparison' => [
                        'scopeGroup' => 'public',
                        'period' => 'all_time',
                    ],
                ],
            ])
            ->render();

        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-benchmark-primary-form"] select[name$="[periodYear]"]')->count(),
        );
    }

    public function testStateQuarterChangeReRendersScopeDetailAndPeriodFields(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $scope = $this->seedEligibleBenchmarkScope($user->_real(), 'LiveStateQuarter');
        $stateId = (string) $scope['state']->getId();

        $testComponent = $this->createLiveComponent('BenchmarkSelectionForm', [
            'initialData' => new BenchmarkSelectionFormData(
                new BenchmarkSelectionSideFormData('public', null, 'all'),
                new BenchmarkSelectionSideFormData('public', null, 'all_time'),
            ),
            'preservedQuery' => [],
            'locale' => 'en',
        ])->actingAs($user->_real());

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $updatedRender = $testComponent
            ->submitForm([
                $formName => [
                    'primary' => [
                        'scopeGroup' => 'state',
                        'period' => 'quarter',
                        'scopeDetail' => $stateId,
                    ],
                    'comparison' => [
                        'scopeGroup' => 'public',
                        'period' => 'all_time',
                    ],
                ],
            ])
            ->render();

        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-benchmark-primary-form"] select[name$="[scopeDetail]"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-benchmark-primary-form"] select[name$="[periodQuarter]"]')->count(),
        );
    }

    public function testHospitalMonthChangeReRendersHospitalAndMonthFields(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $this->seedEligibleBenchmarkScope($user->_real(), 'LiveHospitalMonth');

        $testComponent = $this->createLiveComponent('BenchmarkSelectionForm', [
            'initialData' => new BenchmarkSelectionFormData(
                new BenchmarkSelectionSideFormData('public', null, 'all'),
                new BenchmarkSelectionSideFormData('public', null, 'all_time'),
            ),
            'preservedQuery' => ['gender' => 'male'],
            'locale' => 'en',
        ])->actingAs($user->_real());

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $updatedRender = $testComponent
            ->submitForm([
                $formName => [
                    'primary' => [
                        'scopeGroup' => 'public',
                        'period' => 'all',
                    ],
                    'comparison' => [
                        'scopeGroup' => 'my_hospitals',
                        'period' => 'month',
                    ],
                ],
            ])
            ->render();

        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-benchmark-comparison-form"] select[name$="[scopeDetail]"]')->count(),
        );
        self::assertGreaterThan(
            0,
            $updatedRender->crawler()->filter('[data-testid="stats-benchmark-comparison-form"] select[name$="[periodMonth]"]')->count(),
        );
    }

    public function testApplyRedirectsAfterLiveUpdate(): void
    {
        $user = UserFactory::createOne(['username' => 'live-benchmark-apply-'.bin2hex(random_bytes(4))]);

        $initialData = new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('public', null, 'all'),
            new BenchmarkSelectionSideFormData('public', null, 'all_time'),
        );

        $testComponent = $this->createLiveComponent('BenchmarkSelectionForm', [
            'initialData' => $initialData,
            'preservedQuery' => [],
            'locale' => 'en',
        ])->actingAs($user->_real());

        $initialRender = $testComponent->render();
        $formName = $initialRender->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $testComponent
            ->submitForm([
                $formName => [
                    'primary' => [
                        'scopeGroup' => 'public',
                        'period' => 'year',
                    ],
                    'comparison' => [
                        'scopeGroup' => 'public',
                        'period' => 'all_time',
                    ],
                ],
            ])
            ->call('apply');

        self::assertSame(302, $testComponent->response()->getStatusCode());
        $location = (string) $testComponent->response()->headers->get('Location');
        self::assertStringContainsString(StatisticsQueryKeys::SCOPE.'=public', $location);
        self::assertStringContainsString(StatisticsQueryKeys::PERIOD.'=year', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARISON_SCOPE.'=public', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARISON_PERIOD.'=all_time', $location);
    }

    public function testApplyRedirectsWithStateScopeQueryParams(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $scope = $this->seedEligibleBenchmarkScope($user->_real(), 'LiveApplyState');
        $stateId = (string) $scope['state']->getId();

        $testComponent = $this->createLiveComponent('BenchmarkSelectionForm', [
            'initialData' => new BenchmarkSelectionFormData(
                new BenchmarkSelectionSideFormData('public', null, 'all'),
                new BenchmarkSelectionSideFormData('public', null, 'all_time'),
            ),
            'preservedQuery' => ['gender' => 'male'],
            'locale' => 'en',
        ])->actingAs($user->_real());

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $testComponent
            ->submitForm([
                $formName => [
                    'primary' => [
                        'scopeGroup' => 'state',
                        'period' => 'quarter',
                        'scopeDetail' => $stateId,
                    ],
                    'comparison' => [
                        'scopeGroup' => 'public',
                        'period' => 'all_time',
                    ],
                ],
            ])
            ->call('apply');

        self::assertSame(302, $testComponent->response()->getStatusCode());
        $location = (string) $testComponent->response()->headers->get('Location');
        self::assertStringContainsString('gender=male', $location);
        self::assertStringContainsString(StatisticsQueryKeys::SCOPE.'=state:'.$stateId, $location);
        self::assertStringContainsString(StatisticsQueryKeys::STATE.'='.$stateId, $location);
        self::assertStringContainsString(StatisticsQueryKeys::PERIOD.'=quarter', $location);
        self::assertStringContainsString(StatisticsQueryKeys::YEAR.'=', $location);
        self::assertStringContainsString(StatisticsQueryKeys::QUARTER.'=', $location);
    }

    public function testApplyRedirectsAfterLoginStyleCsrfSession(): void
    {
        $client = $this->client();
        $user = UserFactory::createOne(['username' => 'live-benchmark-csrf-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user->_real());
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/benchmarking', [
            'scope' => 'public',
            'period' => 'all',
            'comparison_scope' => 'public',
            'comparison_period' => 'all_time',
        ]);
        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }

        $client->getRequest()->getSession()->set('csrf-token', 2);

        $initialData = new BenchmarkSelectionFormData(
            new BenchmarkSelectionSideFormData('public', null, 'all'),
            new BenchmarkSelectionSideFormData('public', null, 'all_time'),
        );

        $testComponent = $this->createLiveComponent('BenchmarkSelectionForm', [
            'initialData' => $initialData,
            'preservedQuery' => [],
            'locale' => 'en',
        ], $client);

        $testComponent->render();
        $testComponent->call('apply');

        self::assertSame(302, $testComponent->response()->getStatusCode());
    }
}
