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
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

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
            ->setEntityLabelInSingular(new TranslatableMessage('admin.cookie_consent.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.cookie_consent.entity.plural', domain: 'admin'))
            ->setPageTitle(Crud::PAGE_INDEX, new TranslatableMessage('admin.cookie_consent.entity.plural', domain: 'admin'))
            ->setPageTitle(Crud::PAGE_DETAIL, new TranslatableMessage('admin.cookie_consent.page_title.detail', domain: 'admin'))
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
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.identity', domain: 'admin'));
        yield TextField::new('subjectId', new TranslatableMessage('admin.cookie_consent.field.subject_id', domain: 'admin'));
        yield AssociationField::new('user', new TranslatableMessage('admin.field.user', domain: 'admin'));

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.consent', domain: 'admin'));
        yield TextField::new('consentVersion', new TranslatableMessage('admin.cookie_consent.field.version', domain: 'admin'))
            ->hideOnIndex();
        yield TextField::new('consentMode', new TranslatableMessage('admin.cookie_consent.field.mode', domain: 'admin'))
            ->setHelp(new TranslatableMessage('admin.cookie_consent.field.mode_help', domain: 'admin'))
            ->setTemplatePath('@Admin/crud/field/consent_mode_icon.html.twig')
            ->renderAsHtml()
            ->setValue('')
            ->formatValue(static function (mixed $_, CookieConsent $consent): string {
                $prefs = $consent->getPreferences();

                if ($prefs['performance']) {
                    return 'analytics_performance';
                }

                return $prefs['analytics'] ? 'analytics_enabled' : 'essential_only';
            });
        yield DateTimeField::new('decidedAt', new TranslatableMessage('admin.cookie_consent.field.decided_at', domain: 'admin'))
            ->setFormat('yyyy-MM-dd HH:mm:ss');

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated_at', domain: 'messages'))
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->onlyOnDetail();
    }
}
