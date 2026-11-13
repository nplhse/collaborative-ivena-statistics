<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Feedback;

use App\Feedback\Application\FeedbackQuarantineReview;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackClassification;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<Feedback>
 */
#[IsGranted('ROLE_ADMIN')]
final class FeedbackCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FeedbackRepository $feedbackRepository,
        private readonly FeedbackQuarantineReview $review,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Feedback::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.feedback.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.feedback.entity.plural', domain: 'admin'))
            ->setPageTitle(
                Crud::PAGE_INDEX,
                fn (): TranslatableMessage => new TranslatableMessage('admin.feedback.page_title.index', [
                    'open' => $this->feedbackRepository->countOpen(),
                    'quarantined' => $this->feedbackRepository->countQuarantined(),
                ], 'admin'),
            )
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Feedback $f): string => 'Feedback #'.($f->getId() ?? '?'))
            ->setSearchFields(['message', 'guestEmail', 'routeName', 'appVersion'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /** The default list shows normal feedback only; quarantined entries are reviewed separately. */
    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        return $queryBuilder
            ->andWhere(\sprintf('%s.classification = :feedbackClassification', $queryBuilder->getRootAliases()[0]))
            ->setParameter('feedbackClassification', FeedbackClassification::NORMAL);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $quarantine = Action::new('quarantineFeedback', new TranslatableMessage('admin.feedback.action.quarantine', domain: 'admin'), 'fas fa-shield-halved')
            ->linkToUrl(fn (Feedback $feedback): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction('quarantineFeedback')
                ->setEntityId($feedback->getId())
                ->set('token', $this->container->get('security.csrf.token_manager')->getToken(FeedbackQuarantineCrudController::CSRF_TOKEN_ID)->getValue())
                ->generateUrl())
            ->renderAsForm()
            ->askConfirmation(new TranslatableMessage('admin.feedback.action.quarantine.confirm', domain: 'admin'));

        return $actions
            ->disable(Action::NEW, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $quarantine)
            ->add(Crud::PAGE_DETAIL, $quarantine)
            ->add(Crud::PAGE_EDIT, Action::INDEX);
    }

    /** A quarantined entry is only shown in the quarantine view, never here. */
    #[\Override]
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        return $this->redirectQuarantined($context) ?? parent::detail($context);
    }

    #[\Override]
    public function edit(AdminContext $context): KeyValueStore|Response
    {
        return $this->redirectQuarantined($context) ?? parent::edit($context);
    }

    #[AdminRoute(path: '/{entityId}/move-to-quarantine', name: 'move_to_quarantine', options: ['methods' => ['POST']])]
    public function quarantineFeedback(#[MapEntity(id: 'entityId')] Feedback $feedback, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(FeedbackQuarantineCrudController::CSRF_TOKEN_ID, $request->query->getString('token'))) {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.feedback.invalid_token', domain: 'admin'));
        } else {
            $reviewer = $this->getUser();
            if (!$reviewer instanceof User) {
                throw $this->createAccessDeniedException();
            }

            $this->review->quarantine($feedback, $reviewer);
            $this->addFlash('success', new TranslatableMessage('flash.admin.feedback.quarantined', domain: 'admin'));
        }

        return $this->redirect($this->adminUrlGenerator->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    /**
     * EasyAdmin wraps strings returned by formatValue() in Twig Markup, so they are printed unescaped;
     * feedback text comes from anonymous visitors and must be escaped here.
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param AdminContext<Feedback> $context */
    private function redirectQuarantined(AdminContext $context): ?RedirectResponse
    {
        $feedback = $context->getEntity()->getInstance();
        if (!$feedback instanceof Feedback || !$feedback->isQuarantined()) {
            return null;
        }

        return $this->redirect($this->adminUrlGenerator
            ->unsetAll()
            ->setController(FeedbackQuarantineCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($feedback->getId())
            ->generateUrl());
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.status', domain: 'admin'));
        yield ChoiceField::new('status', new TranslatableMessage('label.status', domain: 'messages'))
            ->setChoices([
                'Open' => FeedbackStatus::NEW,
                'Done' => FeedbackStatus::DONE,
            ])
            ->renderAsBadges([
                FeedbackStatus::NEW->value => 'warning',
                FeedbackStatus::DONE->value => 'success',
            ]);

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.submission', domain: 'admin'));
        yield IdField::new('id')->onlyOnDetail();

        yield ChoiceField::new('category', new TranslatableMessage('label.category', domain: 'messages'))
            ->setChoices([
                'Bug' => FeedbackCategory::BUG,
                'Improvement' => FeedbackCategory::IMPROVEMENT,
                'Question' => FeedbackCategory::QUESTION,
                'Other' => FeedbackCategory::OTHER,
            ])
            ->renderAsBadges()
            ->hideOnForm();

        yield TextField::new('guestEmail', new TranslatableMessage('admin.feedback.field.guest_email', domain: 'admin'))
            ->hideOnForm();

        yield AssociationField::new('submittedBy', new TranslatableMessage('admin.field.user', domain: 'admin'))
            ->hideOnForm();

        if (Crud::PAGE_INDEX === $pageName) {
            yield TextField::new('message', new TranslatableMessage('label.title', domain: 'messages'))
                ->formatValue(static function (?string $value): string {
                    if (!\is_string($value) || '' === $value) {
                        return '—';
                    }

                    return self::escape(mb_strlen($value) > 80 ? mb_substr($value, 0, 80).'…' : $value);
                });
        } elseif (Crud::PAGE_EDIT === $pageName) {
            yield TextareaField::new('message', new TranslatableMessage('admin.feedback.field.message', domain: 'admin'))
                ->setDisabled()
                ->setNumOfRows(8);
        } else {
            yield TextareaField::new('message', new TranslatableMessage('admin.feedback.field.message', domain: 'admin'))->setNumOfRows(12);
        }

        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->hideOnForm();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.context', domain: 'admin'));
        if (Crud::PAGE_DETAIL === $pageName) {
            yield TextField::new('pageUrl', new TranslatableMessage('feedback.email.label.url', domain: 'feedback'))
                ->hideOnForm();
            yield TextField::new('pagePathDisplay', new TranslatableMessage('label.path', domain: 'messages'))
                ->setVirtual(true)
                ->setValue('')
                ->formatValue(static fn (mixed $_, Feedback $f): string => self::escape($f->getPagePath()));
        }

        yield TextField::new('routeName', new TranslatableMessage('feedback.email.label.route', domain: 'feedback'))
            ->hideOnForm()
            ->formatValue(static function (?string $value): string {
                if (!\is_string($value) || '' === trim($value)) {
                    return '—';
                }

                return self::escape($value);
            });

        // Virtual: TextConfigurator rejects the array value of "context" before formatValue() runs.
        yield TextField::new('contextJson', new TranslatableMessage('admin.fieldset.context', domain: 'admin'))
            ->setVirtual(true)
            ->setValue('')
            ->onlyOnDetail()
            ->setSortable(false)
            ->setTemplatePath('@Admin/crud/field/json_pre.html.twig')
            ->formatValue(static function (mixed $_, Feedback $feedback): string {
                $ctx = $feedback->getContext();
                if (null === $ctx || [] === $ctx) {
                    return '—';
                }
                try {
                    return self::escape(json_encode($ctx, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } catch (\JsonException) {
                    return '// invalid JSON';
                }
            });

        yield TextField::new('userAgent', new TranslatableMessage('admin.feedback.field.user_agent', domain: 'admin'))->hideOnIndex()->hideOnForm();
        yield TextField::new('appVersion', new TranslatableMessage('feedback.email.label.app_version', domain: 'feedback'))->hideOnForm();
    }
}
