<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Import;

use App\Import\Domain\Entity\ImportBatchRun;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<ImportBatchRun>
 */
#[IsGranted('ROLE_ADMIN')]
final class ImportBatchRunCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return ImportBatchRun::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.import_batch_run.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.import_batch_run.entity.plural', domain: 'admin'))
            ->setDefaultSort(['startedAt' => 'DESC']);
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
        yield IdField::new('id')
            ->onlyOnDetail();
        yield ChoiceField::new('status', new TranslatableMessage('label.status', domain: 'messages'))
            ->renderAsBadges();
        yield DateTimeField::new('startedAt', new TranslatableMessage('admin.job_run.field.started_at', domain: 'admin'))
            ->setFormat('dd.MM.yyyy HH:mm');
        yield DateTimeField::new('finishedAt', new TranslatableMessage('admin.job_run.field.finished_at', domain: 'admin'))
            ->setFormat('dd.MM.yyyy HH:mm');
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->onlyOnDetail();
        yield TextField::new('optionsPreview', new TranslatableMessage('admin.import_batch_run.field.options', domain: 'admin'))
            ->onlyOnDetail()
            ->setValue('')
            ->formatValue(static function (mixed $_, ImportBatchRun $run): string {
                $encoded = json_encode(
                    $run->getOptions(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
                );

                return \is_string($encoded) ? $encoded : '';
            });
        yield AssociationField::new('items', new TranslatableMessage('admin.import_batch_run.field.items', domain: 'admin'))
            ->onlyOnDetail();
    }
}
