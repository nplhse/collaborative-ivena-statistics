<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form;

use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestSubmission;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\UI\Form\Transformer\HospitalToPublicIdTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Request form for a hospital assignment (#975). The hospital is chosen by name from a list of every hospital; a name
 * that matches no entry is sent as the name of a hospital the participant could not find.
 *
 * @extends AbstractType<HospitalAssignmentRequestSubmission>
 */
final class HospitalAssignmentRequestType extends AbstractType
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this form type */
    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<array{publicId: string, label: string}> $choices */
        $choices = $options['hospital_choices'];

        $builder
            ->add('hospitalName', TextType::class, [
                'required' => false,
                'label' => 'hospital_assignment_request.field.hospital',
                'help' => 'hospital_assignment_request.field.hospital_help',
                'attr' => [
                    'placeholder' => 'hospital_assignment_request.field.hospital_placeholder',
                    'autocomplete' => 'off',
                    'data-controller' => 'datalist-chooser',
                    'data-datalist-chooser-strict-value' => 'false',
                ],
            ])
            ->add('hospital', HiddenType::class)
            ->add('reason', EnumType::class, [
                'class' => HospitalAssignmentReason::class,
                'expanded' => true,
                'label' => 'hospital_assignment_request.field.reason',
                'choice_label' => static fn (HospitalAssignmentReason $reason): string => 'hospital_assignment_request.reason.'.$reason->value,
            ])
            ->add('explanation', TextareaType::class, [
                'required' => false,
                'label' => 'hospital_assignment_request.field.explanation',
                'help' => 'hospital_assignment_request.field.explanation_help',
                'attr' => ['rows' => 2],
            ])
            ->add('comment', TextareaType::class, [
                'required' => false,
                'label' => 'hospital_assignment_request.field.comment',
                'attr' => ['rows' => 3],
            ])
            ->add('uploadAuthority', CheckboxType::class, [
                'required' => false,
                'label' => 'hospital_assignment_request.field.upload_authority',
                'help' => 'hospital_assignment_request.field.upload_authority_help',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'hospital_assignment_request.action.submit',
            ]);

        $builder->get('hospital')->addModelTransformer(new HospitalToPublicIdTransformer($this->hospitalRepository));

        // Without JavaScript the hidden field stays empty: a name that is exactly a listed hospital still selects it.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) use ($choices): void {
            $submission = $event->getData();
            if (!$submission instanceof HospitalAssignmentRequestSubmission || $submission->hospital instanceof Hospital) {
                return;
            }

            $name = mb_strtolower(trim((string) $submission->hospitalName));
            foreach ($choices as $choice) {
                if ('' !== $name && mb_strtolower($choice['label']) === $name) {
                    $hospital = $this->hospitalRepository->findOneByPublicId($choice['publicId']);
                    $submission->hospital = $hospital instanceof Hospital ? $hospital : null;

                    return;
                }
            }
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HospitalAssignmentRequestSubmission::class,
            'translation_domain' => 'allocation',
            'hospital_choices' => [],
        ]);
        $resolver->setAllowedTypes('hospital_choices', 'array');
    }
}
