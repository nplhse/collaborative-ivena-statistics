<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class PageContentBlockType extends AbstractType
{
    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires form types */
    public function __construct(
        private readonly PageContentBlockDataFieldsConfigurator $blockFields,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'label.block_type',
                'choices' => [
                    'label.block_type.richtext' => 'richtext',
                    'label.block_type.image' => 'image',
                    'label.block_type.cta' => 'cta',
                    'label.block_type.quote' => 'quote',
                ],
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'label.enabled',
                'required' => false,
            ])
            ->add('data', FormType::class, [
                'label' => false,
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            if (is_array($data) && is_array($data['data'] ?? null)) {
                $data['data'] = $this->blockFields->hydrateMediaReference($data['data']);
                $event->setData($data);
            }

            $type = is_array($data) && is_string($data['type'] ?? null) ? $data['type'] : null;
            $this->blockFields->replaceFieldsForType($event->getForm()->get('data'), $type);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            $type = is_array($data) && is_string($data['type'] ?? null) ? $data['type'] : null;
            $this->blockFields->replaceFieldsForType($event->getForm()->get('data'), $type);
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_data' => static fn (): array => [
                'type' => 'richtext',
                'enabled' => true,
                'data' => [],
            ],
        ]);
    }
}
