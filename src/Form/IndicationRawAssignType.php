<?php

namespace App\Form;

use App\Form\Transformer\IndicationToIdTransformer;
use App\Repository\IndicationNormalizedRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractType<array<string,mixed>>
 */
final class IndicationRawAssignType extends AbstractType
{
    public function __construct(
        private IndicationNormalizedRepository $indicationNormalizedRepository,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', IntegerType::class, [
                'label' => 'Raw-Code',
                'disabled' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'Raw-Name',
                'disabled' => true,
            ])
            ->add('target_label', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Target',
                'attr' => [
                    'data-controller' => 'datalist-chooser',
                ],
            ])
            ->add('target', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-datalist-chooser-id-target' => '',
                ],
            ])
        ;

        $builder->get('target')->addModelTransformer(
            new IndicationToIdTransformer($this->indicationNormalizedRepository)
        );
    }
}
