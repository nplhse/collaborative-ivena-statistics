<?php

declare(strict_types=1);

namespace App\Statistics\Application\TopList;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticWidget;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Curated statistics top list.
 *
 * {@see build()} receives {@see StatisticsContext} so hospital scopes such as "My hospitals"
 * can respect the signed-in user (same idea as analysis).
 */
#[AutoconfigureTag('app.statistics.top_list_definition')]
interface TopListDefinitionInterface
{
    public function key(): string;

    /** XLIFF resname for the dropdown and headings. */
    public function labelTranslationKey(): string;

    /** XLIFF resname for the short description under the selector. */
    public function descriptionTranslationKey(): string;

    /** Tabler icon name, aligned with the Explore catalog. */
    public function icon(): string;

    public function supports(StatisticsFilter $filter): bool;

    public function fetchRanking(StatisticsContext $context, int $limit): TopListRanking;

    public function toTableWidget(TopListRanking $ranking): StatisticWidget;

    public function build(StatisticsContext $context, int $limit): StatisticWidget;

    public function tableLabelColumnTranslationKey(): string;

    /**
     * @return list<TopListLimit>
     */
    public function allowedLimits(): array;
}
