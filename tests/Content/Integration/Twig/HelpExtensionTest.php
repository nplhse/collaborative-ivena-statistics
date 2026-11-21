<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Twig;

use App\Content\Domain\Entity\Page;
use App\Tests\Support\Content\HelpPages;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class HelpExtensionTest extends DatabaseKernelTestCase
{
    public function testHelpLinkResolvesTheKeyToTheVisibleArticle(): void
    {
        self::bootKernel();
        $root = HelpPages::root();
        HelpPages::article($root, 'Rejected rows', '/help/rejected-rows', '<p>Text</p>', 0, ['helpKey' => 'import.rejects']);
        HelpPages::article($root, 'Members', '/help/members', '<p>Text</p>', 1, ['helpKey' => 'members', 'visibility' => Page::VISIBILITY_AUTHENTICATED]);
        self::getContainer()->get(RequestStack::class)->push(Request::create('/'));

        self::assertSame('/help/rejected-rows', $this->render("{{ help_link('import.rejects') }}"));
        self::assertSame('none', $this->render("{{ help_link('import.missing') ?? 'none' }}"), 'unknown key');
        self::assertSame('none', $this->render("{{ help_link('members') ?? 'none' }}"), 'hidden from visitors');
        self::assertSame('Rejected rows', $this->render("{{ help_topics()|map(t => t.title)|join(',') }}"), 'visitors see the public topics');
    }

    private function render(string $template): string
    {
        $twig = self::getContainer()->get(Environment::class);

        return $twig->createTemplate($template)->render();
    }
}
