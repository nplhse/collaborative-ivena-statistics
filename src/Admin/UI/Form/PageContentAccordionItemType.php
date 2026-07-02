<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class PageContentAccordionItemType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'label.accordion_item_title',
                'required' => true,
            ])
            ->add('html', TextEditorType::class, [
                'label' => 'label.html',
                'required' => true,
                'attr' => ['rows' => 10],
                'help' => 'help.page.richtext_allowed_tags',
            ])
            ->add('openByDefault', CheckboxType::class, [
                'label' => 'label.accordion_open_by_default',
                'required' => false,
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'content',
        ]);
    }
}
