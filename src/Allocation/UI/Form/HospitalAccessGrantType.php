<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form;

use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\UI\Form\Transformer\UserToIdTransformer;
use App\User\Infrastructure\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<HospitalAccessGrant>
 */
final class HospitalAccessGrantType extends AbstractType
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isCreate = (bool) $options['is_create'];

        if ($isCreate) {
            /** @var list<array{id: int, label: string}> $eligibleUserChoices */
            $eligibleUserChoices = $options['eligible_user_choices'];
            $allowedUserIds = array_map(
                static fn (array $row): int => $row['id'],
                $eligibleUserChoices,
            );

            $builder
                ->add('user_label', TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'label.hospital_access_grant.search_user',
                    'attr' => [
                        'placeholder' => 'label.hospital_access_grant.search_user_placeholder',
                        'autocomplete' => 'off',
                        'data-controller' => 'datalist-chooser',
                    ],
                ])
                ->add('user', HiddenType::class, [
                    'constraints' => [new Assert\NotNull()],
                ]);

            $builder->get('user')->addModelTransformer(
                new UserToIdTransformer($this->userRepository, $allowedUserIds),
            );
        }

        $builder
            ->add('permissionChoices', EnumType::class, [
                'class' => HospitalPermission::class,
                'choices' => HospitalPermission::assignableCases(),
                'multiple' => true,
                'expanded' => true,
                'mapped' => false,
                'label' => 'label.hospital_access_grant.permissions',
                'choice_label' => fn (HospitalPermission $permission): string => $this->translator->trans(
                    'label.hospital_permission.'.$permission->name,
                    [],
                    'allocation',
                ),
                'choice_translation_domain' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'label.btn.save',
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }

            /** @var list<int|string|null> $choices */
            $choices = $data['permissionChoices'] ?? [];
            $statisticsValue = (string) HospitalPermission::Statistics->value;
            $benchmarkingValue = (string) HospitalPermission::Benchmarking->value;

            if (\in_array($benchmarkingValue, $choices, true) && !\in_array($statisticsValue, $choices, true)) {
                $choices[] = $statisticsValue;
            }

            if (!\in_array($statisticsValue, $choices, true)) {
                $choices = array_values(array_filter(
                    $choices,
                    static fn (int|string|null $choice): bool => null !== $choice && (string) $choice !== $benchmarkingValue,
                ));
            }

            $data['permissionChoices'] = $choices;
            $event->setData($data);
        });

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $grant = $event->getData();
            if (!$grant instanceof HospitalAccessGrant) {
                return;
            }

            $choices = [];
            foreach (HospitalPermission::assignableCases() as $permission) {
                if (($grant->getPermissions() & $permission->value) !== 0) {
                    $choices[] = $permission;
                }
            }

            $event->getForm()->get('permissionChoices')->setData($choices);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $grant = $event->getData();
            if (!$grant instanceof HospitalAccessGrant) {
                return;
            }

            /** @var list<HospitalPermission> $choices */
            $choices = $event->getForm()->get('permissionChoices')->getData() ?? [];
            if ([] === $choices) {
                throw new \InvalidArgumentException('At least one permission is required.');
            }

            $grant->setPermissions(HospitalPermissionMask::fromPermissions($choices));
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HospitalAccessGrant::class,
            'translation_domain' => 'messages',
            'is_create' => false,
            'eligible_user_choices' => [],
        ]);

        $resolver->setAllowedTypes('eligible_user_choices', 'array');
    }
}
