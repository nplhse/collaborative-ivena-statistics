<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Form;

use App\Statistics\Benchmarking\UI\Form\Data\BenchmarkSelectionSideFormData;
use App\Statistics\UI\Application\StatisticsFilterFormChoiceProvider;
use App\Statistics\UI\Application\StatisticsFilterScopeChoicePolicy;
use App\Statistics\UI\Application\StatisticsFilterSide;
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use App\User\Domain\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @extends AbstractType<BenchmarkSelectionSideFormData>
 */
final class BenchmarkSelectionSideType extends AbstractType
{
    public function __construct(
        private readonly StatisticsFilterFormChoiceProvider $choiceProvider,
        private readonly BenchmarkSelectionSideFieldsConfigurator $fieldsConfigurator,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $locale */
        $locale = $options['locale'];
        /** @var StatisticsFilterScopeChoicePolicy $scopeChoicePolicy */
        $scopeChoicePolicy = $options['scope_choice_policy'];
        $user = $this->currentUser();

        $builder
            ->add('scopeGroup', PreTranslatedChoiceType::class, [
                'label' => 'stats.filter.scope_label',
                'choices' => array_flip($this->choiceProvider->scopePrimaryChoices($user, $locale, $scopeChoicePolicy)),
            ])
            ->add('period', PreTranslatedChoiceType::class, [
                'label' => 'stats.filter.period_label',
                'choices' => array_flip($this->choiceProvider->periodPrimaryChoices($locale)),
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options): void {
            $data = $event->getData();
            if (!$data instanceof BenchmarkSelectionSideFormData) {
                return;
            }
            /** @var StatisticsFilterSide $side */
            $side = $options['side'];
            /** @var string $locale */
            $locale = $options['locale'];
            /** @var StatisticsFilterScopeChoicePolicy $scopeChoicePolicy */
            $scopeChoicePolicy = $options['scope_choice_policy'];
            $data = $this->choiceProvider->normalizeSideFormData(
                $data,
                $this->currentUser(),
                $side,
                $locale,
                $scopeChoicePolicy,
            );
            $event->setData($data);
            /** @var \Symfony\Component\Form\FormInterface<BenchmarkSelectionSideFormData> $sideForm */
            $sideForm = $event->getForm();
            $this->fieldsConfigurator->configureFields($sideForm, $options, $data);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($options): void {
            $submitted = $event->getData();
            if (!\is_array($submitted)) {
                return;
            }

            if (isset($submitted['scopeDetail']) && (\is_int($submitted['scopeDetail']) || is_float($submitted['scopeDetail']))) {
                $submitted['scopeDetail'] = (string) $submitted['scopeDetail'];
            }

            $data = $event->getForm()->getData();
            if (!$data instanceof BenchmarkSelectionSideFormData) {
                $data = new BenchmarkSelectionSideFormData();
            }

            $preview = clone $data;
            if (isset($submitted['scopeGroup']) && \is_string($submitted['scopeGroup'])) {
                $preview->scopeGroup = $submitted['scopeGroup'];
            }
            if (isset($submitted['period']) && \is_string($submitted['period'])) {
                $preview->period = $submitted['period'];
            }
            if (isset($submitted['periodYear']) && '' !== $submitted['periodYear']) {
                $preview->periodYear = (int) $submitted['periodYear'];
            }
            if (isset($submitted['periodQuarter']) && '' !== $submitted['periodQuarter']) {
                $preview->periodQuarter = (int) $submitted['periodQuarter'];
            }
            if (isset($submitted['periodMonth']) && '' !== $submitted['periodMonth']) {
                $preview->periodMonth = (int) $submitted['periodMonth'];
            }
            if (isset($submitted['scopeDetail']) && (\is_string($submitted['scopeDetail']) || is_int($submitted['scopeDetail']))) {
                $preview->scopeDetail = (string) $submitted['scopeDetail'];
            }

            /** @var \Symfony\Component\Form\FormInterface<BenchmarkSelectionSideFormData> $sideForm */
            $sideForm = $event->getForm();
            $this->fieldsConfigurator->configureFields($sideForm, $options, $preview);

            if ($event->getForm()->has('periodYear') && !isset($submitted['periodYear'])) {
                $submitted['periodYear'] = (string) $event->getForm()->get('periodYear')->getConfig()->getData();
            }
            if ($event->getForm()->has('periodQuarter') && !isset($submitted['periodQuarter'])) {
                $submitted['periodQuarter'] = (string) $event->getForm()->get('periodQuarter')->getConfig()->getData();
            }
            if ($event->getForm()->has('periodMonth') && !isset($submitted['periodMonth'])) {
                $submitted['periodMonth'] = (string) $event->getForm()->get('periodMonth')->getConfig()->getData();
            }
            if ($event->getForm()->has('scopeDetail') && !isset($submitted['scopeDetail'])) {
                $submitted['scopeDetail'] = (string) $event->getForm()->get('scopeDetail')->getConfig()->getData();
            }

            $event->setData($submitted);
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BenchmarkSelectionSideFormData::class,
            'side' => StatisticsFilterSide::Primary,
            'locale' => 'en',
            'translation_domain' => 'statistics',
            'scope_choice_policy' => StatisticsFilterScopeChoicePolicy::RegisteredHospitals,
        ]);

        $resolver->setAllowedTypes('side', StatisticsFilterSide::class);
        $resolver->setAllowedTypes('locale', 'string');
        $resolver->setAllowedTypes('scope_choice_policy', StatisticsFilterScopeChoicePolicy::class);
    }

    private function currentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        return $user instanceof User ? $user : null;
    }
}
