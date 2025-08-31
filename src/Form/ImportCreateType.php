<?php

namespace App\Form;

use App\Entity\Hospital;
use App\Entity\Import;
use App\Entity\User;
use App\Repository\HospitalRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

/**
 * @extends AbstractType<\App\Entity\Import>
 */
final class ImportCreateType extends AbstractType
{
    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
        private readonly Security $security,
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
                'query_builder' => fn () => $this->hospitalRepository->getQueryBuilderForAccessibleHospitals($user),
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('file', FileType::class, [
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new Assert\NotNull(),
                    new FileConstraint(
                        maxSize: $this->maxSize,
                        mimeTypes: ['text/csv', 'application/vnd.ms-excel', 'text/plain'],
                        mimeTypesMessage: 'label.import.mimeTypes',
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
        ]);
    }
}
