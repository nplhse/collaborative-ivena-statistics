<?php

declare(strict_types=1);

namespace App\Statistics\UI\LiveComponent;

use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\TopListComparisonSelectionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[IsGranted('ROLE_USER')]
#[AsLiveComponent(
    name: 'TopListComparisonSelectionForm',
    template: '@Statistics/live/TopListComparisonSelectionForm.html.twig',
)]
final class TopListComparisonSelectionForm
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    private ?BenchmarkSelectionSideFormData $initialData = null;

    /** @var array<string, bool|float|int|string> */
    #[LiveProp]
    public array $preservedQuery = [];

    #[LiveProp]
    public string $locale = 'en';

    #[LiveProp]
    public string $side = 'comparison';

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly BenchmarkSelectionQueryBuilder $queryBuilder,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, bool|float|int|string> $preservedQuery
     */
    public function mount(
        BenchmarkSelectionSideFormData $initialData,
        array $preservedQuery = [],
        string $locale = 'en',
        string $side = 'comparison',
    ): void {
        $this->initialData = $initialData;
        $this->preservedQuery = $preservedQuery;
        $this->locale = $locale;
        $this->side = $side;
    }

    /**
     * @return FormInterface<BenchmarkSelectionSideFormData>
     */
    #[\Override]
    protected function instantiateForm(): FormInterface
    {
        $data = $this->initialData ?? new BenchmarkSelectionSideFormData();

        return $this->formFactory->create(TopListComparisonSelectionType::class, clone $data, [
            'locale' => $this->locale,
            'side' => $this->filterSide(),
        ]);
    }

    #[LiveAction]
    public function apply(): RedirectResponse
    {
        $this->submitForm(true);

        /** @var BenchmarkSelectionSideFormData $data */
        $data = $this->getForm()->getData();
        $query = StatisticsFilterSide::Primary === $this->filterSide()
            ? $this->queryBuilder->mergePrimarySide($data, $this->preservedQuery)
            : $this->queryBuilder->mergeComparisonSide($data, $this->preservedQuery);
        $report = isset($query['report']) ? (string) $query['report'] : '';
        unset($query['report']);

        return new RedirectResponse(
            $this->urlGenerator->generate('app_stats_top_lists_show', ['report' => $report] + $query),
        );
    }

    private function filterSide(): StatisticsFilterSide
    {
        return StatisticsFilterSide::tryFrom($this->side) ?? StatisticsFilterSide::Comparison;
    }
}
