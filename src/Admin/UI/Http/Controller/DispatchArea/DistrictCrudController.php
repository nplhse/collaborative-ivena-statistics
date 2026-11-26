<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\DispatchArea;

use App\Allocation\Application\Geo\DistrictBoundaryFetch;
use App\Allocation\Application\Geo\DTO\DistrictBoundaryStatus;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Infrastructure\Repository\DistrictRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Districts and the explicit fetch of a missing boundary. Nothing here calls an external service in the request.
 *
 * @extends AbstractCrudController<District>
 */
#[IsGranted('ROLE_ADMIN')]
final class DistrictCrudController extends AbstractCrudController
{
    private const string FETCH_CSRF_TOKEN_ID = 'admin_district_fetch_boundary';

    public function __construct(
        private readonly DistrictRepository $districts,
        private readonly DistrictBoundaryFetch $boundaryFetch,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return District::class;
    }

    #[\Override]
    public function createEntity(string $entityFqcn): District
    {
        // Placeholder until the form maps the submitted key; the field starts empty and is validated.
        return new District('00000', '');
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.district.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.district.entity.plural', domain: 'admin'))
            ->setSearchFields(['ags', 'name'])
            ->setDefaultSort(['ags' => 'ASC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $fetch = $this->createFetchBoundaryAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_INDEX, $fetch)
            ->add(Crud::PAGE_DETAIL, $fetch);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $ags = TextField::new('ags', new TranslatableMessage('admin.district.field.ags', domain: 'admin'))
            ->setFormTypeOption('empty_data', '')
            ->setFormTypeOption('attr', ['maxlength' => 5, 'inputmode' => 'numeric']);
        if (Crud::PAGE_NEW === $pageName) {
            $ags->setFormTypeOption('data', '');
        }
        if (Crud::PAGE_EDIT === $pageName) {
            $ags->setDisabled();
        }

        yield IdField::new('id')->onlyOnDetail();
        yield $ags;
        yield TextField::new('name', new TranslatableMessage('label.name', domain: 'messages'))
            ->setFormTypeOption('empty_data', '');
        yield TextField::new('kind', new TranslatableMessage('admin.district.field.kind', domain: 'admin'));
        yield AssociationField::new('dispatchArea', new TranslatableMessage('admin.district.field.dispatch_area', domain: 'admin'))
            ->setRequired(false);
        yield Field::new('boundary', new TranslatableMessage('admin.district.field.boundary', domain: 'admin'))
            ->setVirtual(true)
            ->hideOnForm()
            ->formatValue(fn (mixed $value, District $district): DistrictBoundaryStatus => $this->statusOf($district))
            ->setTemplatePath('@Admin/crud/field/district_boundary.html.twig');
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield AssociationField::new('createdBy', new TranslatableMessage('label.created_by', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', new TranslatableMessage('label.updated_by', domain: 'messages'))
            ->onlyOnDetail();
    }

    #[AdminRoute(path: '/{entityId}/fetch-boundary', name: 'fetch_boundary', options: ['methods' => ['POST']])]
    public function fetchBoundary(#[MapEntity(id: 'entityId')] District $district, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::FETCH_CSRF_TOKEN_ID, $request->query->getString('token'))) {
            $this->addFlash('danger', new TranslatableMessage('flash.admin.district.fetch.invalid_token', domain: 'admin'));
        } elseif ($this->boundaryFetch->request($district)) {
            $this->addFlash('success', new TranslatableMessage('flash.admin.district.fetch.started', ['name' => $district->getName()], 'admin'));
        } else {
            $this->addFlash('warning', new TranslatableMessage('flash.admin.district.fetch.not_started', ['name' => $district->getName()], 'admin'));
        }

        return $this->redirect($this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($district->getId())
            ->generateUrl());
    }

    private function createFetchBoundaryAction(): Action
    {
        return Action::new(
            'fetchBoundary',
            new TranslatableMessage('admin.district.action.fetch_boundary', domain: 'admin'),
            'fas fa-cloud-arrow-down',
        )
            ->linkToUrl(fn (District $district): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(self::class)
                ->setAction('fetchBoundary')
                ->setEntityId($district->getId())
                ->set('token', $this->container->get('security.csrf.token_manager')->getToken(self::FETCH_CSRF_TOKEN_ID)->getValue())
                ->generateUrl())
            ->renderAsForm()
            ->displayIf(fn (District $district): bool => $this->statusOf($district)->canFetch());
    }

    private function statusOf(District $district): DistrictBoundaryStatus
    {
        return $this->districts->boundaryStatus((int) $district->getId(), $this->clock->now());
    }
}
