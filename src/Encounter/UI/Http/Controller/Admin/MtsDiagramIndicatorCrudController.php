<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Admin;

use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Catalog\MtsCatalogException;
use App\Encounter\Domain\Entity\MtsDiagram;
use App\Encounter\Domain\Entity\MtsDiagramIndicator;
use App\Encounter\Domain\Entity\MtsIndicator;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Diagram–indicator assignments of the MTS catalog in the back office (ADR 032): definitions, not counts. Created
 * and removed, never edited.
 *
 * @extends AbstractCrudController<MtsDiagramIndicator>
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/encounter/mts-assignments', name: 'encounter_mts_assignment')]
final class MtsDiagramIndicatorCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MtsCatalogCuration $curation,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return MtsDiagramIndicator::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('encounter.mts_catalog.assignment.singular', domain: 'encounter'))
            ->setEntityLabelInPlural(new TranslatableMessage('encounter.mts_catalog.assignment.plural', domain: 'encounter'))
            ->setDefaultSort(['diagram' => 'ASC', 'indicator' => 'ASC'])
            ->setPaginatorPageSize(100);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::EDIT, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('diagram', new TranslatableMessage('encounter.mts_catalog.diagram.singular', domain: 'encounter')))
            ->add(EntityFilter::new('indicator', new TranslatableMessage('encounter.mts_catalog.indicator.singular', domain: 'encounter')));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $active = static fn (QueryBuilder $queryBuilder): QueryBuilder => $queryBuilder
            ->andWhere(\sprintf('%s.status <> :merged', $queryBuilder->getRootAliases()[0]))
            ->setParameter('merged', MtsCatalogStatus::Merged)
            ->orderBy(\sprintf('%s.code', $queryBuilder->getRootAliases()[0]), 'ASC');

        yield AssociationField::new('diagram', new TranslatableMessage('encounter.mts_catalog.diagram.singular', domain: 'encounter'))
            ->setQueryBuilder($active)
            ->setRequired(true);
        yield AssociationField::new('indicator', new TranslatableMessage('encounter.mts_catalog.indicator.singular', domain: 'encounter'))
            ->setQueryBuilder($active)
            ->setRequired(true);
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
    }

    /**
     * @param EntityDto<MtsDiagramIndicator>    $entityDto
     * @param AdminContext<MtsDiagramIndicator> $context
     *
     * @return FormBuilderInterface<mixed>
     */
    #[\Override]
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $builder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $assignment = $event->getData();
            $diagram = $assignment instanceof MtsDiagramIndicator ? $assignment->getDiagram() : null;
            $indicator = $assignment instanceof MtsDiagramIndicator ? $assignment->getIndicator() : null;
            if (!$assignment instanceof MtsDiagramIndicator || !$diagram instanceof MtsDiagram || !$indicator instanceof MtsIndicator) {
                $event->getForm()->addError(new FormError($this->message(new MtsCatalogException(MtsCatalogException::ASSIGNMENT_NOT_ACTIVE))));

                return;
            }

            if ($this->curation->assignmentExists($assignment)) {
                $event->getForm()->get('indicator')->addError(new FormError($this->message(new MtsCatalogException(
                    MtsCatalogException::ASSIGNMENT_EXISTS,
                    ['diagram' => $diagram->getCode(), 'indicator' => $indicator->getCode()],
                ))));
            }
        });

        return $builder;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        \assert($entityInstance instanceof MtsDiagramIndicator);
        try {
            $this->curation->assign($entityInstance);
        } catch (MtsCatalogException $exception) {
            $this->addFlash('danger', $this->message($exception));
        }
    }

    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        \assert($entityInstance instanceof MtsDiagramIndicator);
        $this->curation->unassign($entityInstance);
    }

    private function message(MtsCatalogException $exception): string
    {
        return $this->translator->trans($exception->translationKey(), $exception->parameters(), 'encounter');
    }
}
