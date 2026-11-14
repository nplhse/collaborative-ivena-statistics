<?php

declare(strict_types=1);

namespace App\Encounter\UI\Form;

use App\Encounter\Application\Catalog\MtsCatalogEntry;
use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PzcCodeList;
use App\Encounter\Domain\Entity\PlausibilityCell;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A curated problem area of the plausibility table (ADR 033): names, PZC codes (codes and ranges, PzcCodeList) and the
 * points per active MTS diagram (none or +1 … +3).
 *
 * @extends AbstractType<array{germanName: ?string, englishName: ?string, pzcCodes: ?string, diagrams: array<string, ?int>}>
 */
final class PlausibilityAreaType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('germanName', TextType::class, [
                'label' => 'encounter.plausibility.area.field.german_name',
                'help' => 'encounter.plausibility.area.field.german_name_help',
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ])
            ->add('englishName', TextType::class, [
                'label' => 'encounter.plausibility.area.field.english_name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('pzcCodes', TextareaType::class, [
                'label' => 'encounter.plausibility.area.field.pzc_codes',
                'help' => 'encounter.plausibility.area.field.pzc_codes_help',
                'required' => false,
                'attr' => ['rows' => 3],
                'constraints' => [new Callback(static function (mixed $value, ExecutionContextInterface $context): void {
                    try {
                        PzcCodeList::parse((string) $value);
                    } catch (PlausibilityException $exception) {
                        $context->buildViolation('encounter.plausibility.error.pzc_invalid')
                            ->setParameters(['{pzc}' => (string) ($exception->parameters()['pzc'] ?? '')])
                            ->setTranslationDomain('encounter')
                            ->addViolation();
                    }
                })],
            ]);

        $diagrams = $builder->create('diagrams', FormType::class, [
            'label' => 'encounter.plausibility.area.field.diagrams',
            'help' => 'encounter.plausibility.area.field.diagrams_help',
        ]);
        $points = [];
        for ($value = PlausibilityCell::MAX_POINTS; $value >= 1; --$value) {
            $points['+'.$value] = $value;
        }

        /** @var list<MtsCatalogEntry> $entries */
        $entries = $options['diagrams'];
        foreach ($entries as $entry) {
            $diagrams->add($entry->code, ChoiceType::class, [
                'label' => $entry->germanName,
                'translation_domain' => false,
                'choice_translation_domain' => false,
                'choices' => $points,
                'placeholder' => '–',
                'required' => false,
                'row_attr' => ['data-test' => 'encounter-plausibility-diagram-'.$entry->code],
            ]);
        }

        $builder->add($diagrams);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'encounter',
            'csrf_token_id' => 'encounter_plausibility_area',
        ]);
        $resolver->setRequired('diagrams');
        $resolver->setAllowedTypes('diagrams', MtsCatalogEntry::class.'[]');
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'plausibility_area';
    }
}
