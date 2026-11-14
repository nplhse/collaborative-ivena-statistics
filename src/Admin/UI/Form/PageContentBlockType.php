<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Content\Domain\Enum\PageContentBlockType as ContentBlockTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class PageContentBlockType extends AbstractType
{
    /** Unmapped field that carries a refused type change from PRE_SUBMIT to POST_SUBMIT. */
    private const string REFUSED_FIELDS = 'refusedTypeChange';

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires form types */
    public function __construct(
        private readonly PageContentBlockDataFieldsConfigurator $blockFields,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'label.block_type',
                'choices' => ContentBlockTypeEnum::formChoices(),
                'translation_domain' => 'content',
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'label.enabled',
                'required' => false,
                'translation_domain' => 'messages',
            ])
            ->add('data', FormType::class, [
                'label' => false,
                'translation_domain' => 'content',
            ])
            ->add(self::REFUSED_FIELDS, HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            // Visible order of the block; set by the editor, read by PageContentBlockOrder before mapping.
            ->add(PageContentBlockOrder::POSITION_FIELD, HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => ['data-page-block-position' => ''],
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
            $this->handleSubmittedType($event);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $lostFields = $form->get(self::REFUSED_FIELDS)->getData();
            if (!is_string($lostFields) || '' === $lostFields) {
                return;
            }

            $form->addError(new FormError($this->translator->trans('page.editor.type_change_refused', [
                'fields' => $lostFields,
            ], 'content')));
        });
    }

    /**
     * The block form of the collection prototype also carries the data fields of every block type, so the editor can
     * swap them in the browser when the type changes.
     *
     * @param FormInterface<array<string, mixed>> $form
     */
    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $prototypeName = $form->getParent()?->getConfig()->getOption('prototype_name');
        if (!is_string($prototypeName) || $form->getName() !== $prototypeName) {
            return;
        }

        $templates = [];
        foreach (ContentBlockTypeEnum::cases() as $type) {
            $dataForm = $this->formFactory->createNamedBuilder('data', FormType::class, null, [
                'label' => false,
                'translation_domain' => 'content',
                'auto_initialize' => false,
            ])->getForm();
            $this->blockFields->replaceFieldsForType($dataForm, $type->value);
            $dataForm->setParent($form);

            $templates[$type->value] = $dataForm->createView($view);
        }

        $view->vars['type_templates'] = $templates;
    }

    private function handleSubmittedType(FormEvent $event): void
    {
        $form = $event->getForm();
        $submitted = $event->getData();
        if (!is_array($submitted)) {
            return;
        }

        $newType = ContentBlockTypeEnum::tryFromString(is_string($submitted['type'] ?? null) ? $submitted['type'] : null)
            ?? ContentBlockTypeEnum::Richtext;
        $current = $form->getData();
        $originalType = ContentBlockTypeEnum::tryFromString(is_array($current) && is_string($current['type'] ?? null) ? $current['type'] : null)
            ?? ContentBlockTypeEnum::Richtext;
        /** @var array<string, mixed> $submittedData */
        $submittedData = is_array($submitted['data'] ?? null) ? $submitted['data'] : [];

        $dataForm = $form->get('data');
        $this->blockFields->replaceFieldsForType($dataForm, $newType->value);
        $change = PageContentBlockTypeChange::evaluate($originalType, $newType, $submittedData, array_map(strval(...), array_keys($dataForm->all())));

        $submitted[self::REFUSED_FIELDS] = implode(', ', $change->lostFields);
        if ($change->refused) {
            $this->blockFields->replaceFieldsForType($dataForm, $originalType->value);
            $submitted['type'] = $originalType->value;
            $submitted['data'] = array_intersect_key($submittedData, $dataForm->all());
        } else {
            $submitted['data'] = $change->data;
        }

        $event->setData($submitted);
    }

    /**
     * Data of a block the editor adds: rich text, enabled.
     *
     * @return array{type: string, enabled: bool, data: array<string, mixed>}
     */
    public static function newBlockData(): array
    {
        return [
            'type' => ContentBlockTypeEnum::Richtext->value,
            'enabled' => true,
            'data' => [],
        ];
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            // Keep block errors at the block instead of moving them to the top of the form.
            'error_bubbling' => false,
            'empty_data' => self::newBlockData(...),
        ]);
    }
}
