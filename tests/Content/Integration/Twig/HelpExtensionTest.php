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

    public function testHelpLinkComponentRendersAButtonOnlyForAVisibleArticle(): void
    {
        self::bootKernel();
        $root = HelpPages::root();
        HelpPages::article($root, 'Import status', '/help/import-status', '<p>Text</p>', 0, ['helpKey' => 'import.status']);
        HelpPages::article($root, 'Members', '/help/members', '<p>Text</p>', 1, ['helpKey' => 'members', 'visibility' => Page::VISIBILITY_AUTHENTICATED]);
        self::getContainer()->get(RequestStack::class)->push(Request::create('/'));

        $html = $this->render("{{ component('Content:HelpLink', {key: 'import.status'}) }}");
        self::assertStringContainsString('href="/help/import-status"', $html);
        self::assertStringContainsString('data-help-key="import.status"', $html);
        self::assertStringContainsString('class="btn btn-icon btn-ghost-primary d-print-none"', $html);
        self::assertStringContainsString('aria-label="Help for this area"', $html);

        self::assertSame('', trim($this->render("{{ component('Content:HelpLink', {key: 'import.missing'}) }}")), 'unknown key');
        self::assertSame('', trim($this->render("{{ component('Content:HelpLink', {key: 'members'}) }}")), 'hidden from visitors');
        self::assertStringContainsString(
            'class="btn btn-icon btn-ghost-primary d-print-none btn-sm"',
            $this->render("{{ component('Content:HelpLink', {key: 'import.status', class: 'btn-sm'}) }}"),
            'extra classes are added',
        );
    }

    private function render(string $template): string
    {
        $twig = self::getContainer()->get(Environment::class);

        return $twig->createTemplate($template)->render();
    }
}
