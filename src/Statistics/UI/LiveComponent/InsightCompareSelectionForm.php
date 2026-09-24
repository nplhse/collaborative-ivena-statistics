<?php

declare(strict_types=1);

namespace App\Statistics\UI\LiveComponent;

use App\Statistics\Application\Insights\InsightSearchService;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\TopListComparisonSelectionType;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[IsGranted('ROLE_USER')]
#[AsLiveComponent(
    name: 'InsightCompareSelectionForm',
    template: '@Statistics/live/InsightCompareSelectionForm.html.twig',
)]
final class InsightCompareSelectionForm
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
    public string $searchUrl = '';

    #[LiveProp]
    public string $referenceLabelA = '';

    #[LiveProp]
    public string $referenceDetailA = '';

    #[LiveProp]
    public string $subjectADimension = '';

    #[LiveProp]
    public string $subjectAId = '';

    #[LiveProp(writable: true)]
    public string $subjectBDimension = '';

    #[LiveProp(writable: true)]
    public string $subjectBId = '';

    #[LiveProp(writable: true)]
    public string $subjectBLabel = '';

    #[LiveProp]
    public bool $missingSubjectB = false;

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly BenchmarkSelectionQueryBuilder $queryBuilder,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getSearchMaxResults(): int
    {
        return InsightSearchService::COMPARE_MAX_RESULTS;
    }

    /**
     * @param array<string, bool|float|int|string> $preservedQuery
     */
    public function mount(
        BenchmarkSelectionSideFormData $initialData,
        array $preservedQuery = [],
        string $locale = 'en',
        string $searchUrl = '',
        string $referenceLabelA = '',
        string $referenceDetailA = '',
        string $subjectADimension = '',
        string $subjectAId = '',
        string $subjectBDimension = '',
        string $subjectBId = '',
        string $subjectBLabel = '',
    ): void {
        $this->initialData = $initialData;
        $this->preservedQuery = $preservedQuery;
        $this->locale = $locale;
        $this->searchUrl = $searchUrl;
        $this->referenceLabelA = $referenceLabelA;
        $this->referenceDetailA = $referenceDetailA;
        $this->subjectADimension = $subjectADimension;
        $this->subjectAId = $subjectAId;
        $this->subjectBDimension = $subjectBDimension;
        $this->subjectBId = $subjectBId;
        $this->subjectBLabel = $subjectBLabel;
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
            'side' => StatisticsFilterSide::Comparison,
        ]);
    }

    #[LiveAction]
    public function refreshSelection(): void
    {
        $this->submitForm(false);
    }

    #[LiveAction]
    public function apply(): ?RedirectResponse
    {
        try {
            $this->submitForm(true);
        } catch (UnprocessableEntityHttpException) {
            return null;
        }

        if ('' === $this->subjectBDimension || '' === $this->subjectBId) {
            $this->missingSubjectB = true;

            return null;
        }

        $this->missingSubjectB = false;

        /** @var BenchmarkSelectionSideFormData $data */
        $data = $this->getForm()->getData();
        $query = $this->queryBuilder->mergeComparisonSide($data, $this->preservedQuery);
        unset($query[StatisticsQueryKeys::COMPARE]);
        $query[StatisticsQueryKeys::SUBJECT_A_DIMENSION] = $this->subjectADimension;
        $query[StatisticsQueryKeys::SUBJECT_A_ID] = $this->subjectAId;
        $query[StatisticsQueryKeys::SUBJECT_B_DIMENSION] = $this->subjectBDimension;
        $query[StatisticsQueryKeys::SUBJECT_B_ID] = $this->subjectBId;
        unset($query['dimension'], $query['id']);

        return new RedirectResponse($this->urlGenerator->generate('app_stats_insights_compare', $query));
    }
}
