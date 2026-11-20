<?php

declare(strict_types=1);

namespace App\Import\UI\Form;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\Service\PendingImportUploadStore;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\Validation\Constraints\ImportSourceFile;
use App\Import\Infrastructure\Security\Voter\ImportCreateVoter;
use App\User\Domain\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

/**
 * @extends AbstractType<\App\Import\Domain\Entity\Import>
 */
final class ImportCreateType extends AbstractType
{
    /**
     * Import options (#1352): unmapped checkboxes shown in the upload form's Options section. Each one exists
     * only for users the voter attribute grants it to, in the environments where it applies; the controller
     * asks the same attribute before it uses the value. Later options (dry run, ...) are added here.
     *
     * @var array<string, array{attribute: string, label: string, help: string}>
     */
    private const array OPTIONS = [
        'hospitalOverride' => [
            'attribute' => ImportCreateVoter::OPTION_HOSPITAL_OVERRIDE,
            'label' => 'label.import.hospitalOverride',
            'help' => 'label.import.hospitalOverride.help',
        ],
    ];

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
        private readonly Security $security,
        private readonly PendingImportUploadStore $pendingUploads,
        #[Autowire(param: 'app.imports_max_size')]
        private readonly string $maxSize = '5M',
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('You must be logged in to create imports.');
        }

        /** @var array<string, list<int>> $uploadTargets */
        $uploadTargets = $options['upload_targets'];
        /** @var list<ImportTypeExtensionInterface> $extensions */
        $extensions = $options['type_extensions'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'label.import.name',
                'required' => true,
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('hospital', EntityType::class, [
                'label' => 'label.import.hospital',
                'class' => Hospital::class,
                'choice_label' => 'name',
                'placeholder' => 'label.import.selectHospital',
                'query_builder' => fn (): QueryBuilder => $this->hospitalQueryBuilder($uploadTargets),
                // The types allowed per hospital; the `import-type-fields` controller offers only those (#1351).
                'choice_attr' => static fn (Hospital $hospital): array => [
                    'data-import-types' => json_encode(self::typesForHospital($uploadTargets, (int) $hospital->getId()), \JSON_THROW_ON_ERROR),
                ],
                'constraints' => [new Assert\NotNull()],
            ]);

        $choices = $this->typeChoices($uploadTargets, $extensions);
        // Without a type field the import is an allocation import, so a single other type still needs it.
        if (\count($choices) > 1 || (1 === \count($choices) && !\in_array(ImportType::ALLOCATION, $choices, true))) {
            $builder->add('type', ChoiceType::class, [
                'mapped' => false,
                'required' => true,
                'label' => 'label.import.type',
                'data' => array_values($choices)[0],
                'choices' => $choices,
                'choice_attr' => static fn (ImportType $type): array => ['data-import-type' => $type->value],
                'constraints' => [new Assert\NotNull()],
            ]);
        }

        // An option the user may not use does not exist, so a posted value is an extra field.
        foreach (self::OPTIONS as $name => $option) {
            if ($this->security->isGranted($option['attribute'])) {
                $builder->add($name, CheckboxType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => $option['label'],
                    'help' => $option['help'],
                ]);
            }
        }

        foreach ($extensions as $extension) {
            $extension->buildForm($builder);
        }

        $builder
            ->add('file', FileType::class, [
                'label' => 'label.import.file',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'accept' => '.csv,.txt',
                ],
                'constraints' => [
                    new Assert\NotNull(),
                    new ImportSourceFile(),
                    new FileConstraint(
                        maxSize: $this->maxSize,
                    ),
                ],
                'help' => 'label.import.helpFile',
            ])
            // Token of a file the form uploaded in the background (#977); see addPendingUpload().
            ->add('uploadToken', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'label.btn.import',
            ])
        ;

        $userId = (int) $user->getId();
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($userId): void {
            $this->addPendingUpload($event, $userId);
        });
    }

    /**
     * A file uploaded in the background stands in for the file field (#977), so every constraint, the
     * controller's checks and the move into the import storage stay the same as for a direct upload. A file
     * sent with the form wins over a token; a token of another user or an expired one leaves the field empty.
     */
    private function addPendingUpload(FormEvent $event, int $userId): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        $token = \is_string($data['uploadToken'] ?? null) ? $data['uploadToken'] : '';
        if (($data['file'] ?? null) instanceof UploadedFile || '' === $token) {
            $data['uploadToken'] = '';
            $event->setData($data);

            return;
        }

        $file = $this->pendingUploads->uploadedFile($token, $userId);
        if (!$file instanceof UploadedFile) {
            $data['uploadToken'] = '';
        } else {
            $data['file'] = $file;
        }

        $event->setData($data);
    }

    /**
     * Names of the option fields this form has, for the Options section of the template.
     */
    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['import_options'] = array_values(array_filter(
            array_keys(self::OPTIONS),
            $form->has(...),
        ));
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Import::class,
            'translation_domain' => 'import',
            'upload_targets' => [],
            'type_extensions' => [],
        ]);
        $resolver->setAllowedTypes('upload_targets', 'array');
        $resolver->setAllowedTypes('type_extensions', 'array');
    }

    /**
     * Every hospital the user may upload at least one type to ({@see \App\Import\Application\Service\ImportUploadTargets}).
     *
     * @param array<string, list<int>> $uploadTargets
     */
    private function hospitalQueryBuilder(array $uploadTargets): QueryBuilder
    {
        $hospitalIds = array_values(array_unique(array_merge(...array_values($uploadTargets))));
        $queryBuilder = $this->hospitalRepository->createQueryBuilder('h')->orderBy('h.name', 'ASC');

        return [] === $hospitalIds
            ? $queryBuilder->where('1 = 0')
            : $queryBuilder->where('h.id IN (:hospitalIds)')->setParameter('hospitalIds', $hospitalIds);
    }

    /**
     * Types the user may upload somewhere, labelled, in the order allocation, closure, other contexts.
     *
     * @param array<string, list<int>>           $uploadTargets
     * @param list<ImportTypeExtensionInterface> $extensions
     *
     * @return array<string, ImportType>
     */
    private function typeChoices(array $uploadTargets, array $extensions): array
    {
        $labels = [
            ImportType::ALLOCATION->value => 'label.import.type.allocation',
            ImportType::CLOSURE->value => 'label.import.type.closure',
        ];
        foreach ($extensions as $extension) {
            $labels[$extension::importType()->value] = $extension->label();
        }

        $choices = [];
        foreach (array_keys($uploadTargets) as $typeValue) {
            if (isset($labels[$typeValue])) {
                $choices[$labels[$typeValue]] = ImportType::from($typeValue);
            }
        }

        return $choices;
    }

    /**
     * @param array<string, list<int>> $uploadTargets
     *
     * @return list<string>
     */
    private static function typesForHospital(array $uploadTargets, int $hospitalId): array
    {
        return array_keys(array_filter($uploadTargets, static fn (array $hospitalIds): bool => \in_array($hospitalId, $hospitalIds, true)));
    }
}
