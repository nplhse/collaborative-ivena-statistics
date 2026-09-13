<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\LiveComponent;

use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionType;
use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionFormData;
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
    name: 'BenchmarkSelectionForm',
    template: '@Statistics/live/BenchmarkSelectionForm.html.twig',
)]
final class BenchmarkSelectionForm
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    private ?BenchmarkSelectionFormData $initialData = null;

    /** @var array<string, bool|float|int|string> */
    #[LiveProp]
    public array $preservedQuery = [];

    #[LiveProp]
    public string $locale = 'en';

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly BenchmarkSelectionQueryBuilder $queryBuilder,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, bool|float|int|string> $preservedQuery
     */
    public function mount(BenchmarkSelectionFormData $initialData, array $preservedQuery = [], string $locale = 'en'): void
    {
        $this->initialData = $initialData;
        $this->preservedQuery = $preservedQuery;
        $this->locale = $locale;
    }

    /**
     * @return FormInterface<BenchmarkSelectionFormData>
     */
    #[\Override]
    protected function instantiateForm(): FormInterface
    {
        $data = $this->initialData ?? new BenchmarkSelectionFormData();

        return $this->formFactory->create(BenchmarkSelectionType::class, clone $data, [
            'locale' => $this->locale,
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
            // Live Component submitForm() always throws on invalid forms. Re-render
            // the modal with errors instead of turning a validation failure into a 422.
            return null;
        }

        /** @var BenchmarkSelectionFormData $data */
        $data = $this->getForm()->getData();
        $query = $this->queryBuilder->build($data, $this->preservedQuery);

        return new RedirectResponse(
            $this->urlGenerator->generate('app_stats_benchmarking', $query),
        );
    }
}
