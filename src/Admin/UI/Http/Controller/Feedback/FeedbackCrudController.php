<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Feedback;

use App\Feedback\Domain\Entity\Feedback;
use App\Feedback\Domain\Enum\FeedbackCategory;
use App\Feedback\Domain\Enum\FeedbackStatus;
use App\Feedback\Infrastructure\Repository\FeedbackRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<Feedback>
 */
#[IsGranted('ROLE_ADMIN')]
final class FeedbackCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FeedbackRepository $feedbackRepository,
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
            ->setEntityLabelInSingular('Feedback')
            ->setEntityLabelInPlural('Feedback')
            ->setPageTitle(
                Crud::PAGE_INDEX,
                fn (): string => \sprintf(
                    'Feedback — %d open',
                    $this->feedbackRepository->countOpen(),
                ),
            )
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Feedback $f): string => 'Feedback #'.($f->getId() ?? '?'))
            ->setSearchFields(['message', 'guestEmail', 'routeName', 'appVersion'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();

        yield ChoiceField::new('category', 'Category')
            ->setChoices([
                'Bug' => FeedbackCategory::BUG,
                'Improvement' => FeedbackCategory::IMPROVEMENT,
                'Question' => FeedbackCategory::QUESTION,
                'Other' => FeedbackCategory::OTHER,
            ])
            ->renderAsBadges()
            ->hideOnForm();

        yield ChoiceField::new('status', 'Status')
            ->setChoices([
                'Open' => FeedbackStatus::NEW,
                'Done' => FeedbackStatus::DONE,
            ])
            ->renderAsBadges([
                FeedbackStatus::NEW->value => 'warning',
                FeedbackStatus::DONE->value => 'success',
            ]);

        yield TextField::new('guestEmail', 'Guest email')
            ->hideOnForm();

        yield AssociationField::new('submittedBy', 'User')
            ->hideOnForm();

        if (Crud::PAGE_INDEX === $pageName) {
            yield TextField::new('message', 'Title')
                ->formatValue(static function (?string $value): string {
                    if (!\is_string($value) || '' === $value) {
                        return '—';
                    }

                    return \strlen($value) > 80 ? mb_substr($value, 0, 80).'…' : $value;
                });
        } elseif (Crud::PAGE_EDIT === $pageName) {
            yield TextareaField::new('message', 'Message')
                ->setDisabled()
                ->setNumOfRows(8);
        } else {
            yield TextareaField::new('message', 'Message')->setNumOfRows(12);
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            yield TextField::new('pageUrl', 'Page URL')
                ->hideOnForm();
            yield TextField::new('pagePathDisplay', 'Path')
                ->setVirtual(true)
                ->formatValue(static fn (mixed $_, Feedback $f): string => $f->getPagePath());
        }

        yield TextField::new('routeName', 'Route')
            ->hideOnForm();

        yield CodeEditorField::new('context', 'Context')
            ->onlyOnDetail()
            ->setLanguage('javascript')
            ->setNumOfRows(14)
            ->setSortable(false)
            ->formatValue(static function (mixed $_, Feedback $feedback): string {
                $ctx = $feedback->getContext();
                if (null === $ctx || [] === $ctx) {
                    return '—';
                }
                try {
                    return json_encode($ctx, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } catch (\JsonException) {
                    return '// invalid JSON';
                }
            });

        yield TextField::new('userAgent', 'User agent')->hideOnIndex()->hideOnForm();
        yield TextField::new('appVersion', 'App version')->hideOnForm();

        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->hideOnForm();
    }
}
