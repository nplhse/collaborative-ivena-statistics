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

    private const string CATEGORY_HOSPITALS = 'Hospitals';

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
            $category = $definition['category'];

            $existing = $this->repository->findBySlug($definition['slug']);
            if (!$existing instanceof SavedExplorerView) {
                $view = new SavedExplorerView(
                    slug: $definition['slug'],
                    title: $definition['title'],
                    category: $category,
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
                category: $category,
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
     *     category: string,
     *     preferences: array<string, mixed>
     * }>
     */
    public function definitions(): array
    {
        return array_merge(
            $this->allocationDefinitions(),
            $this->hospitalDefinitions(),
        );
    }

    /**
     * @return list<array{
     *     slug: string,
     *     title: string,
     *     description: string,
     *     category: string,
     *     preferences: array<string, mixed>
     * }>
     */
    private function allocationDefinitions(): array
    {
        return [
            [
                'slug' => 'allocations-over-time',
                'title' => 'Allocations over time',
                'description' => 'Monthly allocation counts over the selected period.',
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
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
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'secondary_indication',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'title' => 'Secondary indication distribution',
                ],
            ],
            [
                'slug' => 'transport-time-distribution-by-urgency',
                'title' => 'Transport time distribution by urgency',
                'description' => 'Distribution of transport times in minutes per urgency level as a box plot.',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'urgency',
                    'grain' => 'total',
                    'metric' => 'transport_time_distribution',
                    'chartType' => 'box_plot',
                    'title' => 'Transport time distribution by urgency',
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     slug: string,
     *     title: string,
     *     description: string,
     *     category: string,
     *     preferences: array<string, mixed>
     * }>
     */
    private function hospitalDefinitions(): array
    {
        $hospitalBase = [
            'dataSource' => 'hospitals',
            'hospitalPopulation' => 'participating',
            'grain' => 'total',
            'chartType' => 'bar',
        ];

        return [
            [
                'slug' => 'hospitals-by-cohort',
                'title' => 'Hospitals by master cohort',
                'description' => 'Participating hospital counts grouped by master cohort.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_master_cohort',
                    'metric' => 'hospital_count',
                    'title' => 'Hospitals by master cohort',
                ]),
            ],
            [
                'slug' => 'hospitals-by-tier',
                'title' => 'Hospitals by tier',
                'description' => 'Participating hospital counts grouped by care tier.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metric' => 'hospital_count',
                    'title' => 'Hospitals by tier',
                ]),
            ],
            [
                'slug' => 'hospitals-by-tier-compare',
                'title' => 'Hospitals by tier (participation compare)',
                'description' => 'Hospital counts by tier split into participating and non-participating.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metric' => 'hospital_count',
                    'hospitalPopulation' => 'compare',
                    'chartType' => 'grouped_bar',
                    'title' => 'Hospitals by tier (participation compare)',
                ]),
            ],
            [
                'slug' => 'hospitals-by-size',
                'title' => 'Hospitals by size',
                'description' => 'Participating hospital counts and average beds grouped by size class.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_size',
                    'metrics' => ['hospital_count', 'avg_beds'],
                    'visualMetric' => 'hospital_count',
                    'title' => 'Hospitals by size',
                ]),
            ],
            [
                'slug' => 'hospital-tier-by-location',
                'title' => 'Hospital tier by location',
                'description' => 'Participating hospital counts in a tier-by-location matrix.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'rows' => ['dimension' => 'hospital_tier', 'grain' => 'total'],
                    'columns' => ['dimension' => 'hospital_location', 'grain' => 'total'],
                    'metric' => 'hospital_count',
                    'chartType' => 'grouped_bar',
                    'title' => 'Hospital tier by location',
                ]),
            ],
            [
                'slug' => 'allocations-per-hospital-tier',
                'title' => 'Allocations per hospital tier',
                'description' => 'How many allocations participating hospitals handle per care tier.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metrics' => ['total_allocations', 'avg_allocations_per_hospital'],
                    'visualMetric' => 'total_allocations',
                    'title' => 'Allocations per hospital tier',
                ]),
            ],
            [
                'slug' => 'beds-distribution-by-tier',
                'title' => 'Beds distribution by tier',
                'description' => 'Distribution of hospital bed counts per care tier as a box plot.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metric' => 'beds_distribution',
                    'chartType' => 'box_plot',
                    'title' => 'Beds distribution by tier',
                ]),
            ],
            [
                'slug' => 'allocations-distribution-by-tier',
                'title' => 'Allocations distribution by tier',
                'description' => 'Distribution of allocations per hospital by care tier as a box plot.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metric' => 'allocations_per_hospital_distribution',
                    'chartType' => 'box_plot',
                    'title' => 'Allocations distribution by tier',
                ]),
            ],
            [
                'slug' => 'transport-time-distribution-by-tier',
                'title' => 'Transport time distribution by tier',
                'description' => 'Distribution of median transport times per hospital by care tier as a box plot.',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metric' => 'transport_time_per_hospital_distribution',
                    'chartType' => 'box_plot',
                    'title' => 'Transport time distribution by tier',
                ]),
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
     * @param array{slug: string, title: string, description: string, category: string, preferences: array<string, mixed>} $definition
     * @param array<string, mixed>                                                                                         $configJson
     */
    private function isUpToDate(SavedExplorerView $existing, array $definition, array $configJson, User $admin): bool
    {
        return $existing->getTitle() === $definition['title']
            && $existing->getDescription() === $definition['description']
            && $definition['category'] === $existing->getCategory()
            && $existing->isSystem()
            && $existing->getConfigJson() === $configJson
            && $existing->wasCreatedBy($admin);
    }
}
