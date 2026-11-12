<?php

declare(strict_types=1);

namespace App\Encounter\UI\Form;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Domain\Validation\Constraints\ImportSourceFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Upload of a canonical encounter exchange file (#1048): hospital, schema, file and the required
 * confirmation that the file was produced with the data preparation tool and holds no direct
 * identifiers (ADR 025).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class EncounterImportType extends AbstractType
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        #[Autowire(param: 'app.imports_max_size')]
        private readonly string $maxSize = '5M',
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $schemas */
        $schemas = $options['schemas'];

        $builder
            ->add('hospital', ChoiceType::class, [
                'label' => 'encounter.import.field.hospital',
                'choices' => $options['hospitals'],
                'choice_value' => static fn (?Hospital $hospital): string => $hospital instanceof Hospital ? (string) $hospital->getId() : '',
                'choice_label' => static fn (Hospital $hospital): string => (string) $hospital->getName(),
                'choice_translation_domain' => false,
                'placeholder' => 'encounter.import.field.hospital_placeholder',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('name', TextType::class, [
                'label' => 'encounter.import.field.name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('schema', ChoiceType::class, [
                'label' => 'encounter.import.field.schema',
                'help' => 'encounter.import.field.schema_help',
                'choices' => array_combine($schemas, $schemas),
                'choice_translation_domain' => false,
                'data' => $schemas[0] ?? null,
                'constraints' => [new Assert\NotBlank(), new Assert\Choice(choices: $schemas)],
            ])
            ->add('file', FileType::class, [
                'label' => 'encounter.import.field.file',
                'help' => 'encounter.import.field.file_help',
                'attr' => ['accept' => '.csv,.txt'],
                'constraints' => [
                    new Assert\NotNull(),
                    new ImportSourceFile(),
                    new Assert\File(maxSize: $this->maxSize),
                ],
            ])
            ->add('confirmation', CheckboxType::class, [
                'label' => 'encounter.import.field.confirmation',
                'required' => true,
                'constraints' => [new Assert\IsTrue(message: 'validation.encounter.import.confirmation_required')],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'encounter',
            'hospitals' => [],
            'schemas' => [],
        ]);
        $resolver->setAllowedTypes('hospitals', 'array');
        $resolver->setAllowedTypes('schemas', 'array');
    }
}
