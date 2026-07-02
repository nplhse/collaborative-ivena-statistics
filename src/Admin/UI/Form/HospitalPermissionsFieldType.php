<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Admin\UI\Form\DataTransformer\HospitalPermissionsMaskTransformer;
use App\Allocation\Domain\Enum\HospitalPermission;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<int>
 */
final class HospitalPermissionsFieldType extends AbstractType
{
    public function __construct(
        private readonly HospitalPermissionsMaskTransformer $transformer,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer($this->transformer);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => HospitalPermission::class,
            'multiple' => true,
            'choices' => HospitalPermission::assignableCases(),
            'choice_label' => static fn (HospitalPermission $permission): string => $permission->name,
        ]);
    }

    #[\Override]
    public function getParent(): string
    {
        return EnumType::class;
    }
}
