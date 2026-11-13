<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Feedback;

use App\Feedback\Application\FeedbackMailPreview;
use App\Feedback\Application\FeedbackQuarantineReview;
use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackClassification;
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
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Deliberately opened review of suspected spam (issue #964). Messages are shown as escaped plain text, links
 * are never clickable and nothing remote is loaded. Releasing sends no notification.
 *
 * @extends AbstractCrudController<Feedback>
 */
#[IsGranted('ROLE_ADMIN')]
final class FeedbackQuarantineCrudController extends AbstractCrudController
{
    public const string CSRF_TOKEN_ID = 'feedback_quarantine_review';

    private const string BATCH_RELEASE = 'batchRelease';

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
            ->setEntityLabelInSingular(new TranslatableMessage('admin.feedback_quarantine.entity', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.feedback_quarantine.entity', domain: 'admin'))
            ->setPageTitle(Crud::PAGE_INDEX, fn (): TranslatableMessage => new TranslatableMessage(
                'admin.feedback_quarantine.title',
                ['count' => $this->feedbackRepository->countQuarantined()],
                'admin',
            ))
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Feedback $f): TranslatableMessage => new TranslatableMessage(
                'admin.feedback_quarantine.detail_title',
                ['id' => $f->getId() ?? 0],
                'admin',
            ))
            ->setHelp(Crud::PAGE_INDEX, new TranslatableMessage('admin.feedback_quarantine.help', domain: 'admin'))
            ->setSearchFields(['message', 'guestEmail'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        return $queryBuilder
            ->andWhere(\sprintf('%s.classification = :feedbackClassification', $queryBuilder->getRootAliases()[0]))
            ->setParameter('feedbackClassification', FeedbackClassification::QUARANTINED);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $release = Action::new('releaseFeedback', new TranslatableMessage('admin.feedback.action.release', domain: 'admin'), 'fas fa-check')
            ->linkToUrl(fn (Feedback $feedback): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction('releaseFeedback')
                ->setEntityId($feedback->getId())
                ->set('token', $this->container->get('security.csrf.token_manager')->getToken(self::CSRF_TOKEN_ID)->getValue())
                ->generateUrl())
            ->renderAsForm()
            ->askConfirmation(new TranslatableMessage('admin.feedback.action.release.confirm', domain: 'admin'));

        $batchRelease = Action::new(self::BATCH_RELEASE, new TranslatableMessage('admin.feedback.action.batch_release', domain: 'admin'), 'fas fa-check')
            ->linkToCrudAction(self::BATCH_RELEASE)
            ->createAsBatchAction();

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $release)
            ->add(Crud::PAGE_DETAIL, $release)
            ->addBatchAction($batchRelease);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield DateTimeField::new('createdAt', new TranslatableMessage('admin.feedback.field.created', domain: 'admin'))
            ->setFormat('yyyy-MM-dd HH:mm');
        yield ChoiceField::new('category', new TranslatableMessage('admin.feedback.field.category', domain: 'admin'))
            ->setChoices([
                'Bug' => FeedbackCategory::BUG,
                'Improvement' => FeedbackCategory::IMPROVEMENT,
                'Question' => FeedbackCategory::QUESTION,
                'Other' => FeedbackCategory::OTHER,
            ])
            ->renderAsBadges();
        yield TextField::new('guestEmail', new TranslatableMessage('admin.feedback.field.guest_email', domain: 'admin'));
        yield AssociationField::new('submittedBy', new TranslatableMessage('admin.feedback.field.user', domain: 'admin'));
        yield ArrayField::new('classificationReasons', new TranslatableMessage('admin.feedback.field.reasons', domain: 'admin'))
            ->setSortable(false);
        yield IntegerField::new('spamScore', new TranslatableMessage('admin.feedback.field.score', domain: 'admin'));

        if (Crud::PAGE_INDEX === $pageName) {
            // Short, link-free preview. No title attribute, so the full message does not show on hover.
            yield TextField::new('message', new TranslatableMessage('admin.feedback.field.preview', domain: 'admin'))
                ->setSortable(false)
                ->setTemplatePath('@Admin/crud/field/text_without_title.html.twig')
                ->formatValue(static fn (?string $value): string => self::escape(mb_strimwidth(FeedbackMailPreview::fromMessage((string) $value), 0, 80, '…')));

            return;
        }

        yield DateTimeField::new('classifiedAt', new TranslatableMessage('admin.feedback.field.quarantined_at', domain: 'admin'))
            ->setFormat('yyyy-MM-dd HH:mm:ss');
        yield AssociationField::new('classifiedBy', new TranslatableMessage('admin.feedback.field.reviewer', domain: 'admin'));

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.submission', domain: 'admin'));
        // Plain text in a <pre>: escaped by Twig, links stay text, nothing remote is loaded.
        yield TextField::new('message', new TranslatableMessage('admin.feedback.field.message', domain: 'admin'))
            ->setTemplatePath('@Admin/crud/field/plain_text_pre.html.twig');
        yield TextField::new('pageUrl', new TranslatableMessage('admin.feedback.field.page', domain: 'admin'))
            ->formatValue(static fn (mixed $_, Feedback $f): string => self::escape($f->getPagePath()));
        yield TextField::new('userAgent', new TranslatableMessage('admin.feedback.field.user_agent', domain: 'admin'));
    }

    /** Normal entries are never shown here. */
    #[\Override]
    public function detail(AdminContext $context): KeyValueStore|Response
    {
        return $this->redirectNormal($context) ?? parent::detail($context);
    }

    /** Deletes a quarantined entry; normal entries cannot be deleted from here. */
    #[\Override]
    public function delete(AdminContext $context): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('ea-delete', (string) $context->getRequest()->request->get('token'))) {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.feedback.invalid_token', domain: 'admin'));

            return $this->redirectToIndex();
        }

        $feedback = $context->getEntity()->getInstance();
        $deleted = $feedback instanceof Feedback ? $this->review->delete([$feedback]) : 0;
        $this->addFlash('success', new TranslatableMessage('flash.admin.feedback.deleted', ['count' => $deleted], 'admin'));

        return $this->redirectToIndex();
    }

    #[\Override]
    public function batchDelete(AdminContext $context, BatchActionDto $batchActionDto): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('ea-batch-action-'.Action::BATCH_DELETE.'-'.$batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.feedback.invalid_token', domain: 'admin'));

            return $this->redirectToIndex();
        }

        $deleted = $this->review->delete($this->selectedEntries($batchActionDto));
        $this->addFlash('success', new TranslatableMessage('flash.admin.feedback.deleted', ['count' => $deleted], 'admin'));

        return $this->redirectToIndex();
    }

    #[AdminRoute(path: '/{entityId}/release', name: 'release', options: ['methods' => ['POST']])]
    public function releaseFeedback(#[MapEntity(id: 'entityId')] Feedback $feedback, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->query->getString('token'))) {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.feedback.invalid_token', domain: 'admin'));

            return $this->redirectToIndex();
        }

        $released = $this->review->release([$feedback], $this->reviewer());
        $this->addFlash('success', new TranslatableMessage('flash.admin.feedback.released', ['count' => $released], 'admin'));

        return $this->redirectToIndex();
    }

    /** @param BatchActionDto<Feedback> $batchActionDto */
    #[AdminRoute(path: '/batch-release', name: 'batch_release', options: ['methods' => ['POST']])]
    public function batchRelease(BatchActionDto $batchActionDto): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('ea-batch-action-'.self::BATCH_RELEASE.'-'.$batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.feedback.invalid_token', domain: 'admin'));

            return $this->redirectToIndex();
        }

        $released = $this->review->release($this->selectedEntries($batchActionDto), $this->reviewer());
        $this->addFlash('success', new TranslatableMessage('flash.admin.feedback.released', ['count' => $released], 'admin'));

        return $this->redirectToIndex();
    }

    /**
     * @param BatchActionDto<Feedback> $batchActionDto
     *
     * @return list<Feedback>
     */
    private function selectedEntries(BatchActionDto $batchActionDto): array
    {
        $ids = array_map(intval(...), $batchActionDto->getEntityIds());

        return [] === $ids ? [] : $this->feedbackRepository->findBy(['id' => $ids]);
    }

    /** @param AdminContext<Feedback> $context */
    private function redirectNormal(AdminContext $context): ?RedirectResponse
    {
        $feedback = $context->getEntity()->getInstance();
        if (!$feedback instanceof Feedback || $feedback->isQuarantined()) {
            return null;
        }

        return $this->redirect($this->adminUrlGenerator
            ->unsetAll()
            ->setController(FeedbackCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($feedback->getId())
            ->generateUrl());
    }

    /** EasyAdmin prints strings returned by formatValue() unescaped (Twig Markup). */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function reviewer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function redirectToIndex(): RedirectResponse
    {
        return $this->redirect($this->adminUrlGenerator->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }
}
