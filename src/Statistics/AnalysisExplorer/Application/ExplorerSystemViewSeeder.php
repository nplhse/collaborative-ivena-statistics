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
            $slug = $definition['slug'];
            $titleKey = self::titleKey($slug);
            $descriptionKey = self::descriptionKey($slug);
            $preferences = $definition['preferences'];
            $preferences['title'] = $titleKey;

            $configJson = $this->configMapper->toStateArray(
                $this->configMapper->buildViewConfig(
                    $this->defaultFilterState(),
                    $preferences,
                    null,
                ),
            );
            $configJson['title'] = $titleKey;
            $category = $definition['category'];

            $existing = $this->repository->findBySlug($slug);
            if (!$existing instanceof SavedExplorerView) {
                $view = new SavedExplorerView(
                    slug: $slug,
                    title: $titleKey,
                    category: $category,
                    configJson: $configJson,
                    description: $descriptionKey,
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
                title: $titleKey,
                category: $category,
                configJson: $configJson,
                description: $descriptionKey,
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
     *     category: string,
     *     preferences: array<string, mixed>
     * }>
     */
    private function allocationDefinitions(): array
    {
        return [
            [
                'slug' => 'allocations-over-time',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'time',
                    'grain' => 'month',
                    'chartType' => 'line',
                ],
            ],
            [
                'slug' => 'allocations-by-year',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'time',
                    'grain' => 'year',
                    'chartType' => 'line',
                ],
            ],
            [
                'slug' => 'gender-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'gender',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'gender-over-time',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'gender',
                    'grain' => 'month',
                    'chartType' => 'grouped_bar',
                ],
            ],
            [
                'slug' => 'urgency-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'urgency',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'urgency-over-time',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'urgency',
                    'grain' => 'year',
                    'chartType' => 'stacked_bar',
                ],
            ],
            [
                'slug' => 'age-group-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'age_group',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'allocations-by-weekday',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'weekday',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'allocations-by-department',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'department',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'transport-type-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'transport_type',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'day-time-bucket-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'day_time_bucket',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'shift-bucket-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'shift_bucket',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'with-physician-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'with_physician',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'secondary-indication-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'secondary_indication',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'transport-time-bucket-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'transport_time_bucket',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'transport-time-distribution-by-urgency',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'urgency',
                    'grain' => 'total',
                    'metrics' => ['transport_time_distribution'],
                    'visualMetric' => 'transport_time_distribution',
                    'chartType' => 'box_plot',
                ],
            ],
            [
                'slug' => 'allocations-weekday-by-day-time-heatmap',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'rows' => ['dimension' => 'weekday', 'grain' => 'total'],
                    'columns' => ['dimension' => 'day_time_bucket', 'grain' => 'total'],
                    'chartType' => 'heatmap',
                ],
            ],
            [
                'slug' => 'allocations-weekday-by-shift-heatmap',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'rows' => ['dimension' => 'weekday', 'grain' => 'total'],
                    'columns' => ['dimension' => 'shift_bucket', 'grain' => 'total'],
                    'chartType' => 'heatmap',
                ],
            ],
            [
                'slug' => 'allocations-by-hour',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'hour',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'overview-clinical-resources',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'clinical_resources',
                    'grain' => 'total',
                    'metrics' => ['prevalence_rate'],
                    'visualMetric' => 'prevalence_rate',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'overview-clinical-features',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'clinical_features',
                    'grain' => 'total',
                    'metrics' => ['prevalence_rate'],
                    'visualMetric' => 'prevalence_rate',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'clinical-resources-by-gender',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'rows' => ['dimension' => 'clinical_resources', 'grain' => 'total'],
                    'columns' => ['dimension' => 'gender', 'grain' => 'total'],
                    'metrics' => ['prevalence_rate'],
                    'visualMetric' => 'prevalence_rate',
                    'chartType' => 'grouped_bar',
                ],
            ],
            [
                'slug' => 'clinical-features-by-urgency',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'rows' => ['dimension' => 'clinical_features', 'grain' => 'total'],
                    'columns' => ['dimension' => 'urgency', 'grain' => 'total'],
                    'metrics' => ['prevalence_rate'],
                    'visualMetric' => 'prevalence_rate',
                    'chartType' => 'grouped_bar',
                ],
            ],
            [
                'slug' => 'resus-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'resus',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'cathlab-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'cathlab',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'cpr-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'cpr',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'ventilation-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'ventilation',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'shock-distribution',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'shock',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
            [
                'slug' => 'allocations-by-speciality',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'speciality',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'chartRowLimit' => '10',
                ],
            ],
            [
                'slug' => 'allocations-by-occasion',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'occasion',
                    'grain' => 'total',
                    'chartType' => 'bar',
                    'chartRowLimit' => '10',
                ],
            ],
            [
                'slug' => 'allocations-by-infection',
                'category' => self::CATEGORY_ALLOCATIONS,
                'preferences' => [
                    'dimension' => 'infection',
                    'grain' => 'total',
                    'chartType' => 'bar',
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     slug: string,
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
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_master_cohort',
                    'metric' => 'hospital_count',
                ]),
            ],
            [
                'slug' => 'hospitals-by-tier',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metric' => 'hospital_count',
                ]),
            ],
            [
                'slug' => 'hospitals-by-tier-compare',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metric' => 'hospital_count',
                    'hospitalPopulation' => 'compare',
                    'chartType' => 'grouped_bar',
                ]),
            ],
            [
                'slug' => 'hospitals-by-size',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_size',
                    'metrics' => ['hospital_count', 'avg_beds'],
                    'visualMetric' => 'hospital_count',
                ]),
            ],
            [
                'slug' => 'hospital-tier-by-location',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'rows' => ['dimension' => 'hospital_tier', 'grain' => 'total'],
                    'columns' => ['dimension' => 'hospital_location', 'grain' => 'total'],
                    'metric' => 'hospital_count',
                    'chartType' => 'grouped_bar',
                ]),
            ],
            [
                'slug' => 'allocations-per-hospital-tier',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metrics' => ['total_allocations', 'avg_allocations_per_hospital'],
                    'visualMetric' => 'total_allocations',
                ]),
            ],
            [
                'slug' => 'beds-distribution-by-tier',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metrics' => ['beds_distribution'],
                    'visualMetric' => 'beds_distribution',
                    'chartType' => 'box_plot',
                ]),
            ],
            [
                'slug' => 'allocations-distribution-by-tier',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metrics' => ['allocations_per_hospital_distribution'],
                    'visualMetric' => 'allocations_per_hospital_distribution',
                    'chartType' => 'box_plot',
                ]),
            ],
            [
                'slug' => 'transport-time-distribution-by-tier',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_tier',
                    'metrics' => ['transport_time_per_hospital_distribution'],
                    'visualMetric' => 'transport_time_per_hospital_distribution',
                    'chartType' => 'box_plot',
                ]),
            ],
            [
                'slug' => 'hospitals-by-location',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_location',
                    'metric' => 'hospital_count',
                ]),
            ],
            [
                'slug' => 'beds-distribution-by-location',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_location',
                    'metrics' => ['beds_distribution'],
                    'visualMetric' => 'beds_distribution',
                    'chartType' => 'box_plot',
                ]),
            ],
            [
                'slug' => 'allocations-per-hospital-size',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_size',
                    'metrics' => ['total_allocations', 'avg_allocations_per_hospital'],
                    'visualMetric' => 'total_allocations',
                ]),
            ],
            [
                'slug' => 'allocations-per-hospital-location',
                'category' => self::CATEGORY_HOSPITALS,
                'preferences' => array_merge($hospitalBase, [
                    'dimension' => 'hospital_location',
                    'metrics' => ['total_allocations', 'avg_allocations_per_hospital'],
                    'visualMetric' => 'total_allocations',
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
     * @param array{slug: string, category: string, preferences: array<string, mixed>} $definition
     * @param array<string, mixed>                                                     $configJson
     */
    private function isUpToDate(SavedExplorerView $existing, array $definition, array $configJson, User $admin): bool
    {
        $slug = $definition['slug'];

        return $existing->getTitle() === self::titleKey($slug)
            && $existing->getDescription() === self::descriptionKey($slug)
            && $definition['category'] === $existing->getCategory()
            && $existing->isSystem()
            && $existing->getConfigJson() === $configJson
            && $existing->wasCreatedBy($admin);
    }

    public static function titleKey(string $slug): string
    {
        return 'stats.analysis_explorer.system_view.'.$slug.'.title';
    }

    public static function descriptionKey(string $slug): string
    {
        return 'stats.analysis_explorer.system_view.'.$slug.'.description';
    }
}
