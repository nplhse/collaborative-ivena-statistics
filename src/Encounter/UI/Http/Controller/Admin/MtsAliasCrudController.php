<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Admin;

use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Catalog\MtsCatalogException;
use App\Encounter\Application\Contract\MtsCatalogRepositoryInterface;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\MtsCatalogItem;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aliases of the MTS catalog in the back office (ADR 032): another spelling (label) whose derived code resolves to an
 * active entry of the same kind. Kind and label are set on creation; afterwards only the target changes. The alias of
 * a merge is changed or removed only by undoing the merge on the merged entry.
 *
 * @extends AbstractCrudController<MtsAlias>
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/encounter/mts-aliases', name: 'encounter_mts_alias')]
final class MtsAliasCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MtsCatalogCuration $curation,
        private readonly MtsCatalogRepositoryInterface $repository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return MtsAlias::class;
    }

    #[\Override]
    public function createEntity(string $entityFqcn): MtsAlias
    {
        return new MtsAlias(MtsCatalogKind::Diagram, '', '', '');
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('encounter.mts_catalog.alias.singular', domain: 'encounter'))
            ->setEntityLabelInPlural(new TranslatableMessage('encounter.mts_catalog.alias.plural', domain: 'encounter'))
            ->setSearchFields(['code', 'label', 'targetCode'])
            ->setDefaultSort(['kind' => 'ASC', 'code' => 'ASC'])
            ->setPaginatorPageSize(100);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $editable = fn (MtsAlias $alias): bool => !$this->curation->isMergeAlias($alias);

        return $actions
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action): Action => $action->displayIf($editable))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action): Action => $action->displayIf($editable))
            ->update(Crud::PAGE_DETAIL, Action::EDIT, static fn (Action $action): Action => $action->displayIf($editable))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn (Action $action): Action => $action->displayIf($editable));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        $kind = ChoiceField::new('kind', new TranslatableMessage('encounter.mts_catalog.field.kind', domain: 'encounter'))
            ->setChoices(MtsCatalogKind::cases());
        yield Crud::PAGE_EDIT === $pageName ? $kind->setDisabled() : $kind;
        yield TextField::new('code', new TranslatableMessage('encounter.mts_catalog.field.code', domain: 'encounter'))
            ->hideOnForm();
        $label = TextField::new('label', new TranslatableMessage('encounter.mts_catalog.field.label', domain: 'encounter'))
            ->setHelp(new TranslatableMessage('encounter.mts_catalog.field.label.help', domain: 'encounter'));
        yield Crud::PAGE_EDIT === $pageName ? $label->setDisabled() : $label;
        yield ChoiceField::new('targetCode', new TranslatableMessage('encounter.mts_catalog.field.target', domain: 'encounter'))
            ->setChoices($this->targetChoices())
            ->setFormTypeOption('choice_translation_domain', false)
            ->setHelp(new TranslatableMessage('encounter.mts_catalog.field.target.help', domain: 'encounter'));
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
    }

    /**
     * @param EntityDto<MtsAlias>    $entityDto
     * @param AdminContext<MtsAlias> $context
     *
     * @return FormBuilderInterface<mixed>
     */
    #[\Override]
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $builder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $alias = $event->getData();
            if (!$alias instanceof MtsAlias || !$event->getForm()->isValid()) {
                return;
            }

            $conflict = $this->curation->conflictFor($alias->getKind(), $alias->getLabel());
            if ($conflict instanceof MtsCatalogException) {
                $event->getForm()->get('label')->addError(new FormError($this->message($conflict)));
            }

            $this->checkTarget($event, $alias);
        });

        return $builder;
    }

    /**
     * @param EntityDto<MtsAlias>    $entityDto
     * @param AdminContext<MtsAlias> $context
     *
     * @return FormBuilderInterface<mixed>
     */
    #[\Override]
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $builder = parent::createEditFormBuilder($entityDto, $formOptions, $context);
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $alias = $event->getData();
            if ($alias instanceof MtsAlias && $event->getForm()->isValid()) {
                $this->checkTarget($event, $alias);
            }
        });

        return $builder;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        \assert($entityInstance instanceof MtsAlias);
        $this->apply(fn () => $this->curation->createAlias($entityInstance));
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        \assert($entityInstance instanceof MtsAlias);
        $this->apply(fn () => $this->curation->updateAlias($entityInstance));
    }

    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        \assert($entityInstance instanceof MtsAlias);
        $this->apply(fn () => $this->curation->deleteAlias($entityInstance));
    }

    /**
     * Active entries of both kinds, grouped; the kind of the alias must match (checked on submit).
     *
     * @return array<string, array<string, string>>
     */
    private function targetChoices(): array
    {
        $choices = [];
        foreach (MtsCatalogKind::cases() as $kind) {
            $group = $this->translator->trans('encounter.mts_catalog.'.$kind->value.'.plural', [], 'encounter');
            foreach ($this->repository->activeEntries($kind) as $entry) {
                $choices[$group][(string) $entry] = $entry->getCode();
            }
        }

        return $choices;
    }

    private function checkTarget(FormEvent $event, MtsAlias $alias): void
    {
        $target = $this->repository->findEntry($alias->getKind(), $alias->getTargetCode());
        if (!$target instanceof MtsCatalogItem || !$target->isActive()) {
            $event->getForm()->get('targetCode')->addError(new FormError($this->message(
                new MtsCatalogException(MtsCatalogException::TARGET_NOT_ACTIVE, ['code' => $alias->getTargetCode()]),
            )));
        }
    }

    private function apply(callable $change): void
    {
        try {
            $change();
        } catch (MtsCatalogException $exception) {
            $this->addFlash('danger', $this->message($exception));
        }
    }

    private function message(MtsCatalogException $exception): string
    {
        return $this->translator->trans($exception->translationKey(), $exception->parameters(), 'encounter');
    }
}
