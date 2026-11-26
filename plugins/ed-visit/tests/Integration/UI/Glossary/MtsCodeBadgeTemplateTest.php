<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\UI\Glossary;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The code badge of the MTS glossary (#1509) adds break opportunities after underscores with `raw`, so the code itself
 * must be escaped first: codes come from imported visits too.
 */
final class MtsCodeBadgeTemplateTest extends KernelTestCase
{
    public function testLongCodesMayWrapOnlyAfterUnderscores(): void
    {
        self::assertStringContainsString('>alter_<wbr>25_<wbr>jahre</span>', $this->render('alter_25_jahre'));
        self::assertStringContainsString('>allergie</span>', $this->render('allergie'));
    }

    public function testTheCodeIsEscapedBeforeTheBreaksAreAdded(): void
    {
        $html = $this->render('<b>neu_code</b>');

        self::assertStringContainsString('&lt;b&gt;neu_<wbr>code&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    private function render(string $code): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('@EdVisit/glossary/_code_badge.html.twig', ['code' => $code]);
    }
}
