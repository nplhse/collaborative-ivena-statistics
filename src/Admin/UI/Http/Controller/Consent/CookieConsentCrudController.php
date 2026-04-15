<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Consent;

use App\Shared\Domain\Entity\CookieConsent;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<CookieConsent>
 */
#[IsGranted('ROLE_ADMIN')]
final class CookieConsentCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return CookieConsent::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cookie consent')
            ->setEntityLabelInPlural('Cookie consents')
            ->setPageTitle(Crud::PAGE_INDEX, 'Cookie consents')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Cookie consent detail')
            ->setSearchFields(['id', 'subjectId', 'consentVersion'])
            ->setDefaultSort(['updatedAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('subjectId', 'Subject ID');
        yield AssociationField::new('user', 'User');
        yield TextField::new('consentVersion', 'Version');
        yield TextField::new('consentMode', 'Mode')
            ->setHelp('Legend: shield = essential only, chart = monitoring enabled.')
            ->setTemplatePath('@Admin/crud/field/consent_mode_icon.html.twig')
            ->renderAsHtml()
            ->setValue('')
            ->formatValue(static function (mixed $_, CookieConsent $consent): string {
                $prefs = $consent->getPreferences();

                return ($prefs['monitoring'] ?? false) ? 'monitoring_enabled' : 'essential_only';
            });
        yield DateTimeField::new('decidedAt', 'Decided at')
            ->setFormat('yyyy-MM-dd HH:mm:ss');
        yield DateTimeField::new('updatedAt', 'Updated at')
            ->setFormat('yyyy-MM-dd HH:mm:ss');
    }
}
