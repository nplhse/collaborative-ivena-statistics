<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Admin;

use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Catalog\MtsCatalogException;
use App\Encounter\Domain\Entity\MtsCatalogItem;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\MtsCatalogSource;
use App\Encounter\Domain\Enum\MtsCatalogStatus;
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
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Back-office maintenance of MTS diagrams or indicators (ADR 032, ROLE_ADMIN): German and English name, source and
 * status. The code is derived from the German name on creation and never changes; entries are never deleted. A
 * provisional entry can be merged into an active entry of the same kind, and the merge undone (MtsCatalogCuration).
 *
 * @template T of MtsCatalogItem
 *
 * @extends AbstractCrudController<T>
 */
abstract class AbstractMtsCatalogItemCrudController extends AbstractCrudController
{
    private const string CSRF_TOKEN_ID = 'encounter_mts_catalog_undo_merge';

    public function __construct(
        private readonly MtsCatalogCuration $curation,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    abstract protected function kind(): MtsCatalogKind;

    /**
     * @return T
     */
    abstract protected function newEntry(): MtsCatalogItem;

    #[\Override]
    public function createEntity(string $entityFqcn): MtsCatalogItem
    {
        return $this->newEntry();
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        $key = 'encounter.mts_catalog.'.$this->kind()->value;

        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage($key.'.singular', domain: 'encounter'))
            ->setEntityLabelInPlural(new TranslatableMessage($key.'.plural', domain: 'encounter'))
            ->setSearchFields(['code', 'germanName', 'englishName'])
            ->setDefaultSort(['code' => 'ASC'])
            ->setPaginatorPageSize(100);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $merge = Action::new('mergeEntry', new TranslatableMessage('encounter.mts_catalog.action.merge', domain: 'encounter'), 'fas fa-code-merge')
            ->linkToUrl(fn (MtsCatalogItem $entry): string => $this->actionUrl('mergeEntry', $entry))
            ->displayIf(static fn (MtsCatalogItem $entry): bool => MtsCatalogStatus::Provisional === $entry->getStatus());

        $undo = Action::new('undoMerge', new TranslatableMessage('encounter.mts_catalog.action.undo_merge', domain: 'encounter'), 'fas fa-rotate-left')
            ->linkToUrl(fn (MtsCatalogItem $entry): string => $this->actionUrl('undoMerge', $entry, [
                'token' => $this->container->get('security.csrf.token_manager')->getToken(self::CSRF_TOKEN_ID)->getValue(),
            ]))
            ->renderAsForm()
            ->askConfirmation(new TranslatableMessage('encounter.mts_catalog.action.undo_merge.confirm', domain: 'encounter'))
            ->displayIf(static fn (MtsCatalogItem $entry): bool => MtsCatalogStatus::Merged === $entry->getStatus());

        return $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_DETAIL, $merge)
            ->add(Crud::PAGE_DETAIL, $undo)
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action): Action => $action->displayIf(
                static fn (MtsCatalogItem $entry): bool => $entry->isActive(),
            ))
            ->update(Crud::PAGE_DETAIL, Action::EDIT, static fn (Action $action): Action => $action->displayIf(
                static fn (MtsCatalogItem $entry): bool => $entry->isActive(),
            ));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('code', new TranslatableMessage('encounter.mts_catalog.field.code', domain: 'encounter'))
            ->hideOnForm();
        yield TextField::new('germanName', new TranslatableMessage('encounter.mts_catalog.field.german_name', domain: 'encounter'))
            ->setHelp(Crud::PAGE_NEW === $pageName
                ? new TranslatableMessage('encounter.mts_catalog.field.german_name.help_new', domain: 'encounter')
                : new TranslatableMessage('encounter.mts_catalog.field.german_name.help_edit', domain: 'encounter'));
        yield TextField::new('englishName', new TranslatableMessage('encounter.mts_catalog.field.english_name', domain: 'encounter'));
        yield ChoiceField::new('source', new TranslatableMessage('encounter.mts_catalog.field.source', domain: 'encounter'))
            ->setChoices(MtsCatalogSource::cases());
        yield ChoiceField::new('status', new TranslatableMessage('encounter.mts_catalog.field.status', domain: 'encounter'))
            ->setChoices(Crud::PAGE_INDEX === $pageName || Crud::PAGE_DETAIL === $pageName
                ? MtsCatalogStatus::cases()
                : [MtsCatalogStatus::Curated, MtsCatalogStatus::Provisional])
            ->renderAsBadges([
                MtsCatalogStatus::Curated->name => 'success',
                MtsCatalogStatus::Provisional->name => 'warning',
                MtsCatalogStatus::Merged->name => 'secondary',
            ]);
        yield DateTimeField::new('firstSeenAt', new TranslatableMessage('encounter.mts_catalog.field.first_seen_at', domain: 'encounter'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
    }

    /**
     * @param EntityDto<T>    $entityDto
     * @param AdminContext<T> $context
     *
     * @return FormBuilderInterface<mixed>
     */
    #[\Override]
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        $builder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $entry = $event->getData();
            if (!$entry instanceof MtsCatalogItem || !$event->getForm()->isValid()) {
                return;
            }

            $conflict = $this->curation->conflictFor($this->kind(), $entry->getGermanName());
            if ($conflict instanceof MtsCatalogException) {
                $event->getForm()->get('germanName')->addError(new FormError($this->message($conflict)));
            }
        });

        return $builder;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        \assert($entityInstance instanceof MtsCatalogItem);
        try {
            $this->curation->createEntry($entityInstance);
        } catch (MtsCatalogException $exception) {
            $this->addFlash('danger', $this->message($exception));
        }
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        \assert($entityInstance instanceof MtsCatalogItem);
        $this->curation->updateEntry($entityInstance);
    }

    #[AdminRoute(path: '/{entityId}/merge', name: 'merge', options: ['methods' => ['GET', 'POST']])]
    public function mergeEntry(Request $request): Response
    {
        $entry = $this->entry($request);
        $blocker = $this->curation->mergeBlocker($entry);
        $targets = [];
        foreach ($this->curation->mergeTargets($entry) as $target) {
            $targets[(string) $target] = $target->getCode();
        }

        $form = $this->createFormBuilder()
            ->add('target', ChoiceType::class, [
                'label' => new TranslatableMessage('encounter.mts_catalog.merge.target', domain: 'encounter'),
                'choices' => $targets,
                'choice_translation_domain' => false,
                'placeholder' => '',
                'required' => true,
            ])
            ->getForm();
        $form->handleRequest($request);

        if (!$blocker instanceof MtsCatalogException && $form->isSubmitted() && $form->isValid()) {
            /** @var array{target: ?string} $data */
            $data = $form->getData();
            $target = $this->entityManager->getRepository(static::getEntityFqcn())->findOneBy(['code' => (string) $data['target']]);
            try {
                if (!$target instanceof MtsCatalogItem) {
                    throw new MtsCatalogException(MtsCatalogException::TARGET_NOT_ACTIVE, ['code' => (string) $data['target']]);
                }

                $this->curation->merge($entry, $target);
                $this->addFlash('success', new TranslatableMessage('encounter.mts_catalog.flash.merged', ['code' => $entry->getCode(), 'target' => $target->getCode()], 'encounter'));

                return $this->redirect($this->actionUrl(Action::DETAIL, $entry));
            } catch (MtsCatalogException $exception) {
                $form->addError(new FormError($this->message($exception)));
            }
        }

        return $this->render('@Encounter/admin/mts_catalog_merge.html.twig', [
            'entry' => $entry,
            'blocker' => $blocker instanceof MtsCatalogException ? $this->message($blocker) : null,
            'form' => $form,
            'back_url' => $this->actionUrl(Action::DETAIL, $entry),
        ]);
    }

    #[AdminRoute(path: '/{entityId}/undo-merge', name: 'undo_merge', options: ['methods' => ['POST']])]
    public function undoMerge(Request $request): RedirectResponse
    {
        $entry = $this->entry($request);
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->query->getString('token'))) {
            $this->addFlash('danger', new TranslatableMessage('encounter.mts_catalog.flash.invalid_token', domain: 'encounter'));

            return $this->redirect($this->actionUrl(Action::DETAIL, $entry));
        }

        try {
            $this->curation->undoMerge($entry);
            $this->addFlash('success', new TranslatableMessage('encounter.mts_catalog.flash.merge_undone', ['code' => $entry->getCode()], 'encounter'));
        } catch (MtsCatalogException $exception) {
            $this->addFlash('danger', $this->message($exception));
        }

        return $this->redirect($this->actionUrl(Action::DETAIL, $entry));
    }

    /**
     * @return T
     */
    private function entry(Request $request): MtsCatalogItem
    {
        $entry = $this->entityManager->find(static::getEntityFqcn(), $request->attributes->getInt('entityId'));
        if (!$entry instanceof MtsCatalogItem) {
            throw new NotFoundHttpException();
        }

        return $entry;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function actionUrl(string $action, MtsCatalogItem $entry, array $parameters = []): string
    {
        $generator = $this->adminUrlGenerator
            ->unsetAll()
            ->setController(static::class)
            ->setAction($action)
            ->setEntityId($entry->getId());
        foreach ($parameters as $name => $value) {
            $generator->set($name, $value);
        }

        return $generator->generateUrl();
    }

    private function message(MtsCatalogException $exception): string
    {
        return $this->translator->trans($exception->translationKey(), $exception->parameters(), 'encounter');
    }
}
