<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisFamily;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisTopic;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\UserRepository;

/**
 * Temporary slim catalog while Analysis Platform v2 is rebuilt slice by slice.
 * Only the active vertical-slice system view is curated here; obsolete system
 * views are removed on sync.
 */
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
        $keptSlugs = [];

        foreach ($this->definitions() as $definition) {
            $slug = $definition['slug'];
            $keptSlugs[] = $slug;
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
            $analysisFamily = $definition['analysisFamily'] ?? null;
            $topics = $definition['topics'] ?? [];

            $existing = $this->repository->findBySlug($slug);
            if (!$existing instanceof SavedExplorerView) {
                $view = new SavedExplorerView(
                    slug: $slug,
                    title: $titleKey,
                    category: $category,
                    configJson: $configJson,
                    description: $descriptionKey,
                    isSystem: true,
                    analysisFamily: $analysisFamily,
                    topics: $topics,
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
                analysisFamily: $analysisFamily,
                topics: $topics,
                updateLibraryMetadata: true,
            );
            if (!$existing->getCreatedBy() instanceof User) {
                $existing->setCreatedBy($admin);
            }
            $existing->setUpdatedBy($admin);
            $this->repository->save($existing);
            ++$updated;
        }

        $removed = $this->removeObsoleteSystemViews($keptSlugs);

        return new ExplorerSystemViewSyncResult($created, $updated, $skipped, $removed);
    }

    /**
     * @return list<array{
     *     slug: string,
     *     category: string,
     *     analysisFamily?: string,
     *     topics?: list<string>,
     *     preferences: array<string, mixed>
     * }>
     */
    public function definitions(): array
    {
        return [
            [
                'slug' => 'allocations-over-time',
                'category' => self::CATEGORY_ALLOCATIONS,
                'analysisFamily' => AnalysisFamily::TimeSeries->value,
                'topics' => [AnalysisTopic::Allocations->value],
                'preferences' => [
                    'dimension' => 'time',
                    'grain' => 'month',
                    'chartType' => 'line',
                ],
            ],
        ];
    }

    /**
     * @param list<string> $keptSlugs
     */
    private function removeObsoleteSystemViews(array $keptSlugs): int
    {
        $removed = 0;
        foreach ($this->repository->findAllSystemViewsOrdered() as $view) {
            $slug = $view->getSlug();
            if (null === $slug || \in_array($slug, $keptSlugs, true)) {
                continue;
            }

            $this->repository->remove($view);
            ++$removed;
        }

        return $removed;
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
     * @param array{
     *     slug: string,
     *     category: string,
     *     analysisFamily?: string,
     *     topics?: list<string>,
     *     preferences: array<string, mixed>
     * } $definition
     * @param array<string, mixed> $configJson
     */
    private function isUpToDate(SavedExplorerView $existing, array $definition, array $configJson, User $admin): bool
    {
        $slug = $definition['slug'];
        $expectedFamily = $definition['analysisFamily'] ?? null;
        $expectedTopics = $definition['topics'] ?? [];

        return $existing->getTitle() === self::titleKey($slug)
            && $existing->getDescription() === self::descriptionKey($slug)
            && $definition['category'] === $existing->getCategory()
            && $expectedFamily === $existing->getAnalysisFamily()
            && $expectedTopics === $existing->getTopics()
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
