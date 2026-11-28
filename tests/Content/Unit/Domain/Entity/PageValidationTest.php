<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Domain\Enum\PageType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Context\ExecutionContextFactory;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validation;

/**
 * The hierarchy and help section rules are callbacks on {@see Page}; the tests call them with a real execution context
 * and compare the raised message templates by property path. The help section is one tree (#596): help articles sit
 * below the help start page or another article, every page there is an article, and only articles have a help key.
 */
final class PageValidationTest extends TestCase
{
    public function testAPageWithoutParentHasAValidHierarchy(): void
    {
        $page = new Page();
        $context = $this->context($page);

        $page->validateHierarchy($context);

        self::assertSame([], $this->messages($context));
    }

    public function testAPageBelowAnotherPageHasAValidHierarchy(): void
    {
        $page = new Page()->setParent(new Page());
        $context = $this->context($page);

        $page->validateHierarchy($context);

        self::assertSame([], $this->messages($context));
    }

    public function testParentMustNotBeSelf(): void
    {
        $page = new Page();
        $page->setParent($page);
        $context = $this->context($page);

        $page->validateHierarchy($context);

        self::assertSame(['parent' => 'page.validation.parent_not_self'], $this->messages($context));
    }

    public function testParentChainMustNotFormCycle(): void
    {
        $a = new Page();
        $b = new Page();
        $c = new Page();
        $a->setParent($b);
        $b->setParent($c);
        $c->setParent($a);
        $context = $this->context($a);

        $a->validateHierarchy($context);

        self::assertSame(['parent' => 'page.validation.no_cycles'], $this->messages($context));
    }

    /**
     * @return iterable<string, array{\Closure(): Page}>
     */
    public static function validHelpSectionPages(): iterable
    {
        yield 'help start page' => [static fn (): Page => new Page()->setKey(PageKey::Help)];
        yield 'article below the start page' => [static fn (): Page => new Page()
            ->setType(PageType::Help)
            ->setParent(new Page()->setKey(PageKey::Help))
            ->setHelpKey('import')];
        yield 'article below another article' => [static fn (): Page => new Page()
            ->setType(PageType::Help)
            ->setParent(new Page()->setType(PageType::Help)->setParent(new Page()->setKey(PageKey::Help)))
            ->setHelpKey('import.rejects')];
        yield 'page outside the help' => [static fn (): Page => new Page()->setParent(new Page())];
    }

    /**
     * @param \Closure(): Page $page
     */
    #[DataProvider('validHelpSectionPages')]
    public function testPagesThatFitTheHelpSectionAreValid(\Closure $page): void
    {
        $page = $page();
        $context = $this->context($page);

        $page->validateHelpSection($context);

        self::assertSame([], $this->messages($context));
    }

    /**
     * @return iterable<string, array{\Closure(): Page}>
     */
    public static function articlesWithoutHelpParent(): iterable
    {
        yield 'no parent' => [static fn (): Page => new Page()->setType(PageType::Help)];
        yield 'parent outside the help' => [static fn (): Page => new Page()->setType(PageType::Help)->setParent(new Page())];
    }

    /**
     * @param \Closure(): Page $article
     */
    #[DataProvider('articlesWithoutHelpParent')]
    public function testAnArticleNeedsAParentInTheHelp(\Closure $article): void
    {
        $article = $article();
        $context = $this->context($article);

        $article->validateHelpSection($context);

        self::assertSame(['parent' => 'page.validation.help_parent_required'], $this->messages($context));
    }

    public function testAPageBelowTheHelpMustBeAnArticle(): void
    {
        $page = new Page()->setParent(new Page()->setKey(PageKey::Help));
        $context = $this->context($page);

        $page->validateHelpSection($context);

        self::assertSame(['type' => 'page.validation.help_child_type'], $this->messages($context));
    }

    public function testOnlyArticlesHaveAHelpKey(): void
    {
        $page = new Page()->setHelpKey('import');
        $context = $this->context($page);

        $page->validateHelpSection($context);

        self::assertSame(['helpKey' => 'page.validation.help_key_help_only'], $this->messages($context));
    }

    public function testTheHelpKeyMustBeWellFormed(): void
    {
        $article = new Page()->setType(PageType::Help)->setParent(new Page()->setKey(PageKey::Help))->setHelpKey('Import Rejects');

        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validateProperty($article, 'helpKey');

        self::assertCount(1, $violations);
        self::assertSame('page.validation.help_key_format', $violations->get(0)->getMessageTemplate());
    }

    private function context(Page $page): ExecutionContextInterface
    {
        return new ExecutionContextFactory(new IdentityTranslator())->createContext(Validation::createValidator(), $page);
    }

    /**
     * @return array<string, string> message template by property path
     */
    private function messages(ExecutionContextInterface $context): array
    {
        $messages = [];
        /** @var ConstraintViolationInterface $violation */
        foreach ($context->getViolations() as $violation) {
            $messages[$violation->getPropertyPath()] = $violation->getMessageTemplate();
        }

        return $messages;
    }
}
