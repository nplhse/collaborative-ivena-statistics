<?php

declare(strict_types=1);

namespace App\Import\UI\Form;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Application\Contracts\ImportTypeExtensionInterface;
use App\Import\Application\Service\DevelopmentHospitalOverride;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\Validation\Constraints\ImportSourceFile;
use App\User\Domain\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

/**
 * @extends AbstractType<\App\Import\Domain\Entity\Import>
 */
final class ImportCreateType extends AbstractType
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
        private readonly Security $security,
        private readonly DevelopmentHospitalOverride $hospitalOverride,
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

        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('hospital', EntityType::class, [
                'class' => Hospital::class,
                'choice_label' => 'name',
                'placeholder' => 'label.import.selectHospital',
                'query_builder' => fn (): QueryBuilder => $this->hospitalQueryBuilder($user, $options['type_extensions']),
                'constraints' => [new Assert\NotNull()],
            ]);

        /** @var list<ImportTypeExtensionInterface> $extensions */
        $extensions = $options['type_extensions'];
        $choices = ['label.import.type.allocation' => ImportType::ALLOCATION];
        if (true === ($options['include_closure_type'] ?? false)) {
            $choices['label.import.type.closure'] = ImportType::CLOSURE;
        }

        foreach ($extensions as $extension) {
            $choices[$extension->label()] = $extension::importType();
        }

        if (\count($choices) > 1) {
            $builder->add('type', ChoiceType::class, [
                'mapped' => false,
                'required' => true,
                'label' => 'label.import.type',
                'data' => ImportType::ALLOCATION,
                'choices' => $choices,
                'help' => 'label.import.type.help',
                'constraints' => [new Assert\NotNull()],
            ]);
        }

        // Development only (#878): outside `dev` the field does not exist, so a posted value is an extra field.
        if ($this->hospitalOverride->isAvailable()) {
            $builder->add('hospitalOverride', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'label.import.hospitalOverride',
                'help' => 'label.import.hospitalOverride.help',
            ]);
        }

        foreach ($extensions as $extension) {
            $extension->buildForm($builder);
        }

        $builder
            ->add('file', FileType::class, [
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
            ->add('submit', SubmitType::class, [
                'label' => 'label.btn.import',
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Import::class,
            'translation_domain' => 'import',
            'include_closure_type' => false,
            'type_extensions' => [],
        ]);
        $resolver->setAllowedTypes('include_closure_type', 'bool');
        $resolver->setAllowedTypes('type_extensions', 'array');
    }

    /**
     * Hospitals with the import permission, plus those where the user may upload a type of another
     * context (for example the encounter grant UPLOAD, ADR 027).
     *
     * @param list<ImportTypeExtensionInterface> $extensions
     */
    private function hospitalQueryBuilder(User $user, array $extensions): QueryBuilder
    {
        $queryBuilder = $this->hospitalRepository->getQueryBuilderForHospitalsWithPermission($user, HospitalPermission::Import);
        $extensionHospitalIds = [];
        foreach ($extensions as $extension) {
            $extensionHospitalIds = [...$extensionHospitalIds, ...$extension->uploadHospitalIds($user)];
        }

        if ([] === $extensionHospitalIds) {
            return $queryBuilder;
        }

        /** @var list<int> $importHospitalIds */
        $importHospitalIds = array_map(
            static fn (Hospital $hospital): int => (int) $hospital->getId(),
            $queryBuilder->getQuery()->getResult(),
        );

        return $this->hospitalRepository->createQueryBuilder('h')
            ->where('h.id IN (:hospitalIds)')
            ->setParameter('hospitalIds', array_values(array_unique([...$importHospitalIds, ...$extensionHospitalIds])))
            ->orderBy('h.name', 'ASC');
    }
}
