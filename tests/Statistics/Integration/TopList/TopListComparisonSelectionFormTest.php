<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\TopList;

use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TopListComparisonSelectionFormTest extends WebTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    public function testApplyComparisonSideStartsCompareAndWritesComparisonQuery(): void
    {
        $user = UserFactory::createOne(['username' => 'top-list-compare-b-'.bin2hex(random_bytes(4))]);

        $testComponent = $this->createLiveComponent('TopListComparisonSelectionForm', [
            'initialData' => new BenchmarkSelectionSideFormData('public', null, 'all'),
            'preservedQuery' => [
                'report' => 'top_diagnoses',
                StatisticsQueryKeys::SCOPE => 'public',
                StatisticsQueryKeys::PERIOD => 'all',
                StatisticsQueryKeys::PAGE => 2,
                'gender' => 'male',
            ],
            'locale' => 'en',
            'side' => 'comparison',
        ])->actingAs($user);

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $testComponent
            ->submitForm([
                $formName => [
                    'scopeGroup' => 'public',
                    'period' => 'year',
                ],
            ])
            ->call('apply');

        self::assertSame(302, $testComponent->response()->getStatusCode());
        $location = (string) $testComponent->response()->headers->get('Location');
        self::assertStringContainsString('/statistics/top-lists/top_diagnoses', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARE.'=1', $location);
        self::assertStringContainsString(StatisticsQueryKeys::SCOPE.'=public', $location);
        self::assertStringContainsString(StatisticsQueryKeys::PERIOD.'=all', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARISON_SCOPE.'=public', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARISON_PERIOD.'=year', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARISON_YEAR.'=', $location);
        self::assertStringContainsString('gender=male', $location);
        self::assertStringNotContainsString('page=', $location);
    }

    public function testApplyPrimarySideKeepsComparisonQueryAndRewritesScopePeriod(): void
    {
        $user = UserFactory::createOne(['username' => 'top-list-compare-a-'.bin2hex(random_bytes(4))]);

        $testComponent = $this->createLiveComponent('TopListComparisonSelectionForm', [
            'initialData' => new BenchmarkSelectionSideFormData('public', null, 'all'),
            'preservedQuery' => [
                'report' => 'top_diagnoses',
                StatisticsQueryKeys::SCOPE => 'public',
                StatisticsQueryKeys::PERIOD => 'all',
                StatisticsQueryKeys::COMPARE => '1',
                StatisticsQueryKeys::COMPARISON_SCOPE => 'public',
                StatisticsQueryKeys::COMPARISON_PERIOD => 'all_time',
                StatisticsQueryKeys::PAGE => 2,
            ],
            'locale' => 'en',
            'side' => 'primary',
        ])->actingAs($user);

        $formName = $testComponent->render()->crawler()->filter('form[name]')->attr('name');
        self::assertNotNull($formName);

        $testComponent
            ->submitForm([
                $formName => [
                    'scopeGroup' => 'public',
                    'period' => 'year',
                ],
            ])
            ->call('apply');

        self::assertSame(302, $testComponent->response()->getStatusCode());
        $location = (string) $testComponent->response()->headers->get('Location');
        self::assertStringContainsString('/statistics/top-lists/top_diagnoses', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARE.'=1', $location);
        self::assertStringContainsString(StatisticsQueryKeys::SCOPE.'=public', $location);
        self::assertStringContainsString(StatisticsQueryKeys::PERIOD.'=year', $location);
        self::assertStringContainsString(StatisticsQueryKeys::YEAR.'=', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARISON_SCOPE.'=public', $location);
        self::assertStringContainsString(StatisticsQueryKeys::COMPARISON_PERIOD.'=all_time', $location);
        self::assertStringNotContainsString('page=', $location);
    }
}
