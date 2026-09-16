<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.statistics.insight_dimension')]
interface InsightDimensionProviderInterface
{
    public function key(): InsightDimensionKey;

    /**
     * @return class-string
     */
    public function entityFqcn(): string;

    public function labelTranslationKey(): string;

    public function descriptionTranslationKey(): string;

    public function allValuesLinkTranslationKey(): string;

    public function icon(): string;

    public function navPlacement(): InsightNavPlacement;

    public function navOrder(): int;

    public function nestedUnder(): ?InsightDimensionKey;

    public function featuredOnOverview(): bool;

    public function supportsCompare(): bool;

    public function hasCode(): bool;

    /**
     * Insight ids from IndicationInsightEngine that are tautological for this dimension.
     *
     * @return list<string>
     */
    public function disabledInsightIds(): array;

    public function resolve(int $id): ?InsightSubject;

    /**
     * @return list<array{id: int, label: string, code: ?int, publicId: ?string, contextLabel: ?string}>
     */
    public function searchEntities(string $query, int $limit): array;

    /**
     * @return list<array{id: int, label: string, code: ?int, publicId: ?string, contextLabel: ?string}>
     */
    public function listEntities(?string $search): array;
}
