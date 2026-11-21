<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Hospital;

use App\Admin\UI\Http\Controller\TranslatedEnumChoices;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestDecider;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestRefused;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalAssignmentRequestStatus;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\Enum\PreviousOwnerAccess;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Administrators review hospital assignment requests (#975): a list with the pending ones first, and a detail page
 * with the requester, the hospital, its owner and grants, and the decision forms. A request for a named hospital is
 * matched to an existing hospital first. An approval gives an access grant (preset by the reason) or the ownership;
 * a transfer shows the current owner, needs an explicit confirmation and a choice for the previous owner's access.
 * Every decision is a POST with the version the reviewer saw; the decision itself runs in
 * {@see HospitalAssignmentRequestDecider}.
 *
 * @extends AbstractCrudController<HospitalAssignmentRequest>
 */
#[IsGranted('ROLE_ADMIN')]
final class HospitalAssignmentRequestCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly HospitalAssignmentRequestDecider $decider,
        private readonly HospitalAccessGrantRepository $grants,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return HospitalAssignmentRequest::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.hospital_assignment_request.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.hospital_assignment_request.entity.plural', domain: 'admin'))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['requestedHospitalName', 'hospital.name', 'requester.username', 'requester.email'])
            ->overrideTemplate('crud/detail', '@Admin/hospital_assignment_request/detail.html.twig');
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        $choices = [];
        foreach (HospitalAssignmentRequestStatus::cases() as $status) {
            $choices[$status->value] = new TranslatableMessage('admin.hospital_assignment_request.status.'.$status->value, domain: 'admin');
        }

        return $filters->add(ChoiceFilter::new('status', new TranslatableMessage('admin.hospital_assignment_request.field.status', domain: 'admin'))->setTranslatableChoices($choices));
    }

    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $alias = $queryBuilder->getRootAliases()[0];

        // Pending requests first, then the newest.
        return $queryBuilder->addSelect(\sprintf("CASE WHEN %s.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN pendingFirst", $alias))
            ->orderBy('pendingFirst', 'ASC')
            ->addOrderBy($alias.'.createdAt', 'DESC');
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TranslatedEnumChoices::field('status', new TranslatableMessage('admin.hospital_assignment_request.field.status', domain: 'admin'), HospitalAssignmentRequestStatus::class, static fn (HospitalAssignmentRequestStatus $status): string => 'admin.hospital_assignment_request.status.'.$status->value, 'admin')
            ->renderAsBadges([
                HospitalAssignmentRequestStatus::Pending->value => 'warning',
                HospitalAssignmentRequestStatus::Approved->value => 'success',
                HospitalAssignmentRequestStatus::Rejected->value => 'danger',
                HospitalAssignmentRequestStatus::Withdrawn->value => 'secondary',
            ]);
        yield AssociationField::new('requester', new TranslatableMessage('admin.hospital_assignment_request.field.requester', domain: 'admin'));
        // The chosen hospital, or the entered name marked with "*" while it is not matched.
        yield TextField::new('requestedHospitalName', new TranslatableMessage('admin.hospital_assignment_request.field.hospital', domain: 'admin'))
            ->formatValue(static fn (mixed $_, HospitalAssignmentRequest $request): string => htmlspecialchars(
                $request->getHospital()?->getName() ?? sprintf('%s *', (string) $request->getRequestedHospitalName()),
                \ENT_QUOTES | \ENT_SUBSTITUTE,
                'UTF-8',
            ));
        yield TranslatedEnumChoices::field('reason', new TranslatableMessage('admin.hospital_assignment_request.field.reason', domain: 'admin'), HospitalAssignmentReason::class, static fn (HospitalAssignmentReason $reason): string => 'hospital_assignment_request.reason.'.$reason->value, 'allocation');
        yield DateTimeField::new('createdAt', new TranslatableMessage('admin.hospital_assignment_request.field.created_at', domain: 'admin'));
        yield DateTimeField::new('closedAt', new TranslatableMessage('admin.hospital_assignment_request.field.closed_at', domain: 'admin'))->hideOnIndex();
        yield AssociationField::new('decidedBy', new TranslatableMessage('admin.hospital_assignment_request.field.decided_by', domain: 'admin'))->hideOnIndex();
    }

    #[\Override]
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $responseParameters = parent::configureResponseParameters($responseParameters);
        $context = $this->getContext();
        $request = $context?->getEntity()->getInstance();
        if (Crud::PAGE_DETAIL !== $context?->getCrud()?->getCurrentPage() || !$request instanceof HospitalAssignmentRequest) {
            return $responseParameters;
        }

        $hospital = $request->getHospital();
        $responseParameters->set('assignment_request', $request);
        $responseParameters->set('hospital_grants', $hospital instanceof Hospital ? $this->grants->findForHospital($hospital) : []);
        $responseParameters->set('requester_grants', $this->grants->findForUser($request->getRequester()));
        if ($request->isPending()) {
            $responseParameters->set('decision_forms', array_map(
                static fn (FormInterface $form): \Symfony\Component\Form\FormView => $form->createView(),
                $this->decisionForms($request),
            ));
        }

        return $responseParameters;
    }

    #[AdminRoute(path: '/{entityId}/resolve-hospital', name: 'resolve_hospital', options: ['methods' => ['POST']])]
    public function resolveHospital(#[MapEntity(id: 'entityId')] HospitalAssignmentRequest $assignmentRequest, Request $request): RedirectResponse
    {
        $form = $this->resolveForm($assignmentRequest)->handleRequest($request);
        $hospital = $form->get('hospital')->getData();
        if ($form->isSubmitted() && $form->isValid() && $hospital instanceof Hospital) {
            $this->attempt(fn () => $this->decider->resolveHospital($assignmentRequest, $hospital, $this->version($form)), 'flash.admin.hospital_assignment_request.hospital_resolved');
        } else {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.hospital_assignment_request.invalid', domain: 'admin'));
        }

        return $this->redirectToDetail($assignmentRequest);
    }

    #[AdminRoute(path: '/{entityId}/approve-grant', name: 'approve_grant', options: ['methods' => ['POST']])]
    public function approveGrant(#[MapEntity(id: 'entityId')] HospitalAssignmentRequest $assignmentRequest, Request $request): RedirectResponse
    {
        $form = $this->grantForm($assignmentRequest)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<HospitalPermission> $permissions */
            $permissions = $form->get('permissions')->getData() ?? [];
            $this->attempt(fn () => $this->decider->approveAsAccessGrant(
                $assignmentRequest,
                $this->reviewer(),
                HospitalPermissionMask::fromPermissions($permissions),
                $this->message($form),
                $this->version($form),
            ), 'flash.admin.hospital_assignment_request.approved');
        } else {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.hospital_assignment_request.invalid', domain: 'admin'));
        }

        return $this->redirectToDetail($assignmentRequest);
    }

    #[AdminRoute(path: '/{entityId}/approve-ownership', name: 'approve_ownership', options: ['methods' => ['POST']])]
    public function approveOwnership(#[MapEntity(id: 'entityId')] HospitalAssignmentRequest $assignmentRequest, Request $request): RedirectResponse
    {
        $form = $this->ownershipForm($assignmentRequest)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $expectedOwner = (string) $form->get('expectedOwner')->getData();
            $previousOwnerAccess = $form->has('previousOwnerAccess') ? $form->get('previousOwnerAccess')->getData() : null;
            $this->attempt(fn () => $this->decider->approveAsOwnership(
                $assignmentRequest,
                $this->reviewer(),
                '' === $expectedOwner ? null : (int) $expectedOwner,
                $previousOwnerAccess instanceof PreviousOwnerAccess ? $previousOwnerAccess : null,
                $this->message($form),
                $this->version($form),
            ), 'flash.admin.hospital_assignment_request.approved');
        } else {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.hospital_assignment_request.invalid', domain: 'admin'));
        }

        return $this->redirectToDetail($assignmentRequest);
    }

    #[AdminRoute(path: '/{entityId}/reject', name: 'reject', options: ['methods' => ['POST']])]
    public function reject(#[MapEntity(id: 'entityId')] HospitalAssignmentRequest $assignmentRequest, Request $request): RedirectResponse
    {
        $form = $this->rejectForm($assignmentRequest)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->attempt(fn () => $this->decider->reject($assignmentRequest, $this->reviewer(), $this->message($form), $this->version($form)), 'flash.admin.hospital_assignment_request.rejected');
        } else {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.hospital_assignment_request.invalid', domain: 'admin'));
        }

        return $this->redirectToDetail($assignmentRequest);
    }

    /**
     * @return array<string, FormInterface<array<string, mixed>>>
     */
    private function decisionForms(HospitalAssignmentRequest $request): array
    {
        $forms = ['reject' => $this->rejectForm($request)];
        if ($request->needsHospitalResolution()) {
            return ['resolve' => $this->resolveForm($request), ...$forms];
        }

        return ['grant' => $this->grantForm($request), 'ownership' => $this->ownershipForm($request), ...$forms];
    }

    /**
     * @return FormInterface<array<string, mixed>>
     */
    private function resolveForm(HospitalAssignmentRequest $request): FormInterface
    {
        return $this->container->get('form.factory')->createNamedBuilder('assignment_resolve', FormType::class, ['version' => $request->getVersion()], [
            'action' => $this->actionUrl($request, 'resolveHospital'),
            'translation_domain' => 'admin',
        ])
            ->add('hospital', EntityType::class, [
                'class' => Hospital::class,
                'label' => 'admin.hospital_assignment_request.form.hospital',
                'placeholder' => 'admin.hospital_assignment_request.form.hospital_placeholder',
                'choice_label' => static fn (Hospital $hospital): string => '' === $hospital->getAddress()->getCity() ? (string) $hospital->getName() : \sprintf('%s (%s)', (string) $hospital->getName(), $hospital->getAddress()->getCity()),
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('h')->orderBy('h.name', 'ASC'),
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('version', HiddenType::class)
            ->getForm();
    }

    /**
     * @return FormInterface<array<string, mixed>>
     */
    private function grantForm(HospitalAssignmentRequest $request): FormInterface
    {
        $preset = array_values(array_filter(
            HospitalPermission::assignableCases(),
            static fn (HospitalPermission $permission): bool => ($request->getReason()->suggestedPermissions() & $permission->value) !== 0,
        ));

        return $this->container->get('form.factory')->createNamedBuilder('assignment_grant', FormType::class, ['permissions' => $preset, 'version' => $request->getVersion()], [
            'action' => $this->actionUrl($request, 'approveGrant'),
            'translation_domain' => 'admin',
        ])
            ->add('permissions', EnumType::class, [
                'class' => HospitalPermission::class,
                'choices' => HospitalPermission::assignableCases(),
                'multiple' => true,
                'expanded' => true,
                'label' => 'admin.hospital_assignment_request.form.permissions',
                'help' => 'admin.hospital_assignment_request.form.permissions_help',
                'choice_label' => static fn (HospitalPermission $permission): string => 'label.hospital_permission.'.$permission->name,
                'choice_translation_domain' => 'allocation',
                'constraints' => [new Assert\Count(min: 1)],
            ])
            ->add('message', TextareaType::class, ['required' => false, 'label' => 'admin.hospital_assignment_request.form.message', 'attr' => ['rows' => 2]])
            ->add('version', HiddenType::class)
            ->getForm();
    }

    /**
     * @return FormInterface<array<string, mixed>>
     */
    private function ownershipForm(HospitalAssignmentRequest $request): FormInterface
    {
        $owner = $request->getHospital()?->getOwner();
        $builder = $this->container->get('form.factory')->createNamedBuilder('assignment_ownership', FormType::class, [
            'expectedOwner' => (string) $owner?->getId(),
            'version' => $request->getVersion(),
        ], [
            'action' => $this->actionUrl($request, 'approveOwnership'),
            'translation_domain' => 'admin',
        ])
            ->add('expectedOwner', HiddenType::class);

        if ($owner instanceof User) {
            $builder
                ->add('previousOwnerAccess', EnumType::class, [
                    'class' => PreviousOwnerAccess::class,
                    'expanded' => true,
                    'label' => 'admin.hospital_assignment_request.form.previous_owner_access',
                    'choice_label' => static fn (PreviousOwnerAccess $access): string => 'admin.hospital_assignment_request.previous_owner_access.'.$access->value,
                    'constraints' => [new Assert\NotNull()],
                ])
                ->add('confirmTransfer', CheckboxType::class, [
                    'label' => 'admin.hospital_assignment_request.form.confirm_transfer',
                    // The admin theme renders labels as inline-block; a long one would wrap below its checkbox.
                    'label_attr' => ['class' => 'd-inline'],
                    'mapped' => false,
                    'constraints' => [new Assert\IsTrue()],
                ]);
        }

        return $builder
            ->add('message', TextareaType::class, ['required' => false, 'label' => 'admin.hospital_assignment_request.form.message', 'attr' => ['rows' => 2]])
            ->add('version', HiddenType::class)
            ->getForm();
    }

    /**
     * @return FormInterface<array<string, mixed>>
     */
    private function rejectForm(HospitalAssignmentRequest $request): FormInterface
    {
        return $this->container->get('form.factory')->createNamedBuilder('assignment_reject', FormType::class, ['version' => $request->getVersion()], [
            'action' => $this->actionUrl($request, 'reject'),
            'translation_domain' => 'admin',
        ])
            ->add('message', TextareaType::class, [
                'required' => false,
                'label' => 'admin.hospital_assignment_request.form.rejection_message',
                'help' => 'admin.hospital_assignment_request.form.rejection_message_help',
                'attr' => ['rows' => 2],
            ])
            ->add('version', HiddenType::class)
            ->getForm();
    }

    /**
     * @param callable(): void $decision
     */
    private function attempt(callable $decision, string $successMessage): void
    {
        try {
            $decision();
            $this->addFlash('success', new TranslatableMessage($successMessage, domain: 'admin'));
        } catch (HospitalAssignmentRequestRefused $refused) {
            $this->addFlash('danger', new TranslatableMessage($refused->reason, domain: 'allocation'));
        }
    }

    private function actionUrl(HospitalAssignmentRequest $request, string $action): string
    {
        return $this->adminUrlGenerator->unsetAll()->setController(self::class)->setAction($action)->setEntityId($request->getId())->generateUrl();
    }

    private function redirectToDetail(HospitalAssignmentRequest $request): RedirectResponse
    {
        return $this->redirect($this->adminUrlGenerator->unsetAll()->setController(self::class)->setAction(Action::DETAIL)->setEntityId($request->getId())->generateUrl());
    }

    /**
     * @param FormInterface<array<string, mixed>> $form
     */
    private function version(FormInterface $form): int
    {
        return (int) $form->get('version')->getData();
    }

    /**
     * @param FormInterface<array<string, mixed>> $form
     */
    private function message(FormInterface $form): ?string
    {
        $message = $form->get('message')->getData();

        return \is_string($message) ? $message : null;
    }

    private function reviewer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
