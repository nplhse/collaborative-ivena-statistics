<?php

declare(strict_types=1);

namespace App\Research\UI\Form;

use App\Research\Application\DataRequest\DataRequestDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for a data extract request (ADR 045). The template renders the fields in three groups: visible to
 * participants, export scope, internal details.
 *
 * @extends AbstractType<DataRequestDraft>
 */
final class DataRequestType extends AbstractType
{
    public const array PUBLIC_FIELDS = ['title', 'summary', 'institution', 'enrichmentPlanned'];
    public const array SCOPE_FIELDS = ['purpose', 'periodFrom', 'periodTo', 'population', 'variables', 'granularity', 'recipients', 'enrichmentDetails', 'singleCases'];
    public const array INTERNAL_FIELDS = ['description', 'dataNeeds', 'responsiblePersons', 'conflictsOfInterest', 'ethics', 'plannedEnd', 'plannedDeletion', 'plannedPublication'];

    private const array TEXTAREAS = ['summary', 'purpose', 'population', 'variables', 'granularity', 'recipients', 'enrichmentDetails', 'description', 'dataNeeds', 'responsiblePersons', 'conflictsOfInterest', 'ethics', 'plannedPublication'];
    private const array OPTIONAL = ['enrichmentDetails', 'plannedEnd', 'plannedDeletion', 'plannedPublication'];
    private const array DATES = ['periodFrom', 'periodTo', 'plannedEnd', 'plannedDeletion'];
    private const array CHECKBOXES = ['enrichmentPlanned', 'singleCases'];

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ([...self::PUBLIC_FIELDS, ...self::SCOPE_FIELDS, ...self::INTERNAL_FIELDS] as $field) {
            $key = 'data_request.field.'.$this->snake($field);
            $common = [
                'label' => $key,
                'help' => $key.'_help',
                'required' => !\in_array($field, [...self::OPTIONAL, ...self::CHECKBOXES], true),
            ];

            if (\in_array($field, self::CHECKBOXES, true)) {
                $builder->add($field, CheckboxType::class, $common);
            } elseif (\in_array($field, self::DATES, true)) {
                $builder->add($field, DateType::class, $common + ['widget' => 'single_text', 'input' => 'datetime_immutable']);
            } elseif (\in_array($field, self::TEXTAREAS, true)) {
                $builder->add($field, TextareaType::class, $common + ['attr' => ['rows' => 3]]);
            } else {
                $builder->add($field, TextType::class, $common);
            }
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DataRequestDraft::class,
            'translation_domain' => 'research',
        ]);
    }

    private function snake(string $field): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', $field));
    }
}
