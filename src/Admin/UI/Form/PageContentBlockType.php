<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class PageContentBlockType extends AbstractType
{
    /** @var list<string> */
    private const array DATA_FIELDS = ['html', 'src', 'alt', 'caption', 'headline', 'buttonLabel', 'buttonUrl', 'text'];

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'label.type',
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
            $type = is_array($data) && is_string($data['type'] ?? null) ? $data['type'] : null;
            $this->addTypeSpecificFields($event->getForm(), $type);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            $type = is_array($data) && is_string($data['type'] ?? null) ? $data['type'] : null;
            $this->addTypeSpecificFields($event->getForm(), $type);
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

    /**
     * @param FormInterface<array<string, mixed>> $form
     */
    private function addTypeSpecificFields(FormInterface $form, ?string $type): void
    {
        $dataForm = $form->get('data');

        foreach (self::DATA_FIELDS as $fieldName) {
            if ($dataForm->has($fieldName)) {
                $dataForm->remove($fieldName);
            }
        }

        match ($type) {
            'image' => $this->addImageFields($dataForm),
            'cta' => $this->addCtaFields($dataForm),
            'quote' => $this->addQuoteFields($dataForm),
            default => $this->addRichtextFields($dataForm),
        };
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addRichtextFields(FormInterface $dataForm): void
    {
        $dataForm->add('html', TextareaType::class, [
            'label' => 'label.html',
            'required' => true,
            'attr' => ['rows' => 8],
            'help' => 'help.page.richtext_allowed_tags',
        ]);
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addImageFields(FormInterface $dataForm): void
    {
        $dataForm
            ->add('src', TextType::class, ['label' => 'label.image_url', 'required' => true])
            ->add('alt', TextType::class, ['label' => 'label.image_alt', 'required' => true])
            ->add('caption', TextType::class, ['label' => 'label.image_caption', 'required' => false]);
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addCtaFields(FormInterface $dataForm): void
    {
        $dataForm
            ->add('headline', TextType::class, ['label' => 'label.headline', 'required' => true])
            ->add('buttonLabel', TextType::class, ['label' => 'label.button_label', 'required' => true])
            ->add('buttonUrl', TextType::class, ['label' => 'label.button_url', 'required' => true]);
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addQuoteFields(FormInterface $dataForm): void
    {
        $dataForm->add('text', TextareaType::class, [
            'label' => 'label.quote_text',
            'required' => true,
            'attr' => ['rows' => 4],
        ]);
    }
}
