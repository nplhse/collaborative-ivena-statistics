<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;

final readonly class ExplorerSystemViewSeeder
{
    private const string CATEGORY_ALLOCATIONS = 'Allocations';

    private const string ADMIN_USERNAME = 'admin';

    public function __construct(
        private SavedExplorerViewRepository $repository,
        private ExplorerConfigMapper $configMapper,
        private UserRepository $userRepository,
    ) {
    }

    public function sync(): ExplorerSystemViewSyncResult
    {
        $admin = $this->resolveAdminUser();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->definitions() as $definition) {
            $configJson = $this->configMapper->toStateArray(
                $this->configMapper->buildViewConfig(
                    $this->defaultFilterState(),
                    $definition['preferences'],
                    null,
                ),
            );
            $configJson['title'] = $definition['title'];

            $existing = $this->repository->findBySlug($definition['slug']);
            if (!$existing instanceof SavedExplorerView) {
                $view = new SavedExplorerView(
                    slug: $definition['slug'],
                    title: $definition['title'],
                    category: self::CATEGORY_ALLOCATIONS,
                    configJson: $configJson,
                    description: $definition['description'],
                    isSystem: true,
                );
                $view->setCreatedBy($admin);
                $this->repository->save($view);
                ++$created;
                continue;
            }

            if ($this->isUpToDate($existing, $definition, $configJson, $admin)) {
                ++$skipped;
                continue;
            }

            $existing->update(
                title: $definition['title'],
                category: self::CATEGORY_ALLOCATIONS,
                configJson: $configJson,
                description: $definition['description'],
            );
            if (!$existing->getCreatedBy() instanceof User) {
                $existing->setCreatedBy($admin);
            }
            $existing->setUpdatedBy($admin);
            $this->repository->save($existing);
            ++$updated;
        }

        return new ExplorerSystemViewSyncResult($created, $updated, $skipped);
    }

    /**
     * @return list<array{
     *     slug: string,
     *     title: string,
     *     description: string,
     *     preferences: array<string, mixed>
     * }>
     */
    public function definitions(): array
    {
        return [
            [
                'slug' => 'allocations-over-time',
                'title' => 'Allocations over time',
                'description' => 'Monthly allocation counts over the selected period.',
                'preferences' => [
                    'dimension' => 'time',
                    'grain' => 'month',
                    'chartType' => 'bar',
                    'title' => 'Allocations over time',
                ],
            ],
            [
                'slug' => 'allocations-by-year',
                'title' => 'Allocations by year',
                'description' => 'Yearly allocation totals as a line chart.',
                'preferences' => [
                    'dimension' => 'time',
                    'grain' => 'year',
                    'chartType' => 'line',
                    'title' => 'Allocations by year',
                ],
            ],
            [
                'slug' => 'gender-distribution',
                'title' => 'Gender distribution',
                'description' => 'Allocation counts grouped by patient gender.',
                'preferences' => [
                    'dimension' => 'gender',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Gender distribution',
                ],
            ],
            [
                'slug' => 'gender-over-time',
                'title' => 'Gender over time',
                'description' => 'Monthly allocation counts split by gender.',
                'preferences' => [
                    'dimension' => 'gender',
                    'grain' => 'month',
                    'chartType' => 'grouped_bar',
                    'title' => 'Gender over time',
                ],
            ],
            [
                'slug' => 'urgency-distribution',
                'title' => 'Urgency distribution',
                'description' => 'Allocation counts grouped by urgency level.',
                'preferences' => [
                    'dimension' => 'urgency',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Urgency distribution',
                ],
            ],
            [
                'slug' => 'urgency-over-time',
                'title' => 'Urgency over time',
                'description' => 'Yearly allocation counts stacked by urgency.',
                'preferences' => [
                    'dimension' => 'urgency',
                    'grain' => 'year',
                    'chartType' => 'stacked_bar',
                    'title' => 'Urgency over time',
                ],
            ],
            [
                'slug' => 'age-group-distribution',
                'title' => 'Age group distribution',
                'description' => 'Allocation counts grouped by patient age group.',
                'preferences' => [
                    'dimension' => 'age_group',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Age group distribution',
                ],
            ],
            [
                'slug' => 'allocations-by-weekday',
                'title' => 'Allocations by weekday',
                'description' => 'Allocation counts distributed across weekdays.',
                'preferences' => [
                    'dimension' => 'weekday',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Allocations by weekday',
                ],
            ],
            [
                'slug' => 'allocations-by-department',
                'title' => 'Allocations by department',
                'description' => 'Which departments handle the most allocations.',
                'preferences' => [
                    'dimension' => 'department',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Allocations by department',
                ],
            ],
            [
                'slug' => 'transport-type-distribution',
                'title' => 'Transport type distribution',
                'description' => 'Ground versus air transport allocation counts.',
                'preferences' => [
                    'dimension' => 'transport_type',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Transport type distribution',
                ],
            ],
            [
                'slug' => 'day-time-bucket-distribution',
                'title' => 'Day-time distribution',
                'description' => 'Allocation counts by time-of-day bucket.',
                'preferences' => [
                    'dimension' => 'day_time_bucket',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Day-time distribution',
                ],
            ],
            [
                'slug' => 'shift-bucket-distribution',
                'title' => 'Shift distribution',
                'description' => 'Allocation counts by shift bucket.',
                'preferences' => [
                    'dimension' => 'shift_bucket',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Shift distribution',
                ],
            ],
            [
                'slug' => 'with-physician-distribution',
                'title' => 'Physician accompaniment',
                'description' => 'Allocations with versus without physician accompaniment.',
                'preferences' => [
                    'dimension' => 'with_physician',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Physician accompaniment',
                ],
            ],
            [
                'slug' => 'secondary-indication-distribution',
                'title' => 'Secondary indication distribution',
                'description' => 'Allocation counts grouped by secondary indication.',
                'preferences' => [
                    'dimension' => 'secondary_indication',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Secondary indication distribution',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFilterState(): array
    {
        return [
            'scope' => ['group' => 'public', 'detail' => null],
            'period' => ['type' => 'all', 'year' => null, 'quarter' => null, 'month' => null],
        ];
    }

    private function resolveAdminUser(): User
    {
        $admin = $this->userRepository->findOneBy(['username' => self::ADMIN_USERNAME]);
        if (!$admin instanceof User) {
            throw new \RuntimeException(sprintf('Explorer system views require an admin user with username "%s".', self::ADMIN_USERNAME));
        }

        return $admin;
    }

    /**
     * @param array{slug: string, title: string, description: string, preferences: array<string, mixed>} $definition
     * @param array<string, mixed>                                                                       $configJson
     */
    private function isUpToDate(SavedExplorerView $existing, array $definition, array $configJson, User $admin): bool
    {
        return $existing->getTitle() === $definition['title']
            && $existing->getDescription() === $definition['description']
            && self::CATEGORY_ALLOCATIONS === $existing->getCategory()
            && $existing->isSystem()
            && $existing->getConfigJson() === $configJson
            && $existing->wasCreatedBy($admin);
    }
}
