<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Domain;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Domain\Enum\PageType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The help section is one tree (#596): help articles sit below the help start page or another article, every page
 * there is an article, and only articles have a help key.
 */
final class PageHelpSectionValidationTest extends KernelTestCase
{
    public function testArticlesBelowTheStartPageOrAnotherArticleAreValid(): void
    {
        $root = new Page()->setKey(PageKey::Help);
        $topic = new Page()->setType(PageType::Help)->setParent($root)->setHelpKey('import');
        $article = new Page()->setType(PageType::Help)->setParent($topic)->setHelpKey('import.rejects');

        self::assertSame([], $this->messages($topic));
        self::assertSame([], $this->messages($article));
        self::assertSame([], $this->messages($root));
    }

    public function testAnArticleNeedsAParentInTheHelp(): void
    {
        self::assertSame(['parent' => 'page.validation.help_parent_required'], $this->messages(new Page()->setType(PageType::Help)));
        self::assertSame(
            ['parent' => 'page.validation.help_parent_required'],
            $this->messages(new Page()->setType(PageType::Help)->setParent(new Page())),
        );
    }

    public function testAPageBelowTheHelpMustBeAnArticle(): void
    {
        $root = new Page()->setKey(PageKey::Help);

        self::assertSame(['type' => 'page.validation.help_child_type'], $this->messages(new Page()->setParent($root)));
    }

    public function testOnlyArticlesHaveAWellFormedHelpKey(): void
    {
        self::assertSame(['helpKey' => 'page.validation.help_key_help_only'], $this->messages(new Page()->setHelpKey('import')));

        $article = new Page()->setType(PageType::Help)->setParent(new Page()->setKey(PageKey::Help))->setHelpKey('Import Rejects');
        self::assertSame(['helpKey' => 'page.validation.help_key_format'], $this->messages($article));
    }

    public function testAnEmptyHelpKeyIsNoKey(): void
    {
        self::assertNull(new Page()->setHelpKey('  ')->getHelpKey());
    }

    /**
     * @return array<string, string> message template by property path
     */
    private function messages(Page $page): array
    {
        self::bootKernel();
        $messages = [];
        /** @var ConstraintViolationInterface $violation */
        foreach (self::getContainer()->get(ValidatorInterface::class)->validate($page) as $violation) {
            $messages[$violation->getPropertyPath()] = $violation->getMessageTemplate();
        }

        return $messages;
    }
}
