<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class WelcomeEmailTemplateTest extends KernelTestCase
{
    public function testNextStepsAreRenderedAsANumberedList(): void
    {
        $html = self::getContainer()->get(Environment::class)->render(
            '@User/participation/welcome_email.html.twig',
            [
                'greetingName' => 'Ada',
                'dashboardUrl' => 'https://example.test/',
                'nextSteps' => [
                    [
                        'titleKey' => 'email.welcome.next_steps.explore.title',
                        'descriptionKey' => 'email.welcome.next_steps.explore.description',
                        'url' => 'https://example.test/explore',
                    ],
                    [
                        'titleKey' => 'email.welcome.next_steps.analysis.title',
                        'descriptionKey' => 'email.welcome.next_steps.analysis.description',
                        'url' => 'https://example.test/statistics/',
                    ],
                ],
            ],
        );

        self::assertStringContainsString('Ada', $html);
        self::assertStringContainsString('https://example.test/explore', $html);
        self::assertStringContainsString('https://example.test/statistics/', $html);
        self::assertStringContainsString('bgcolor="#206bc4"', $html);
        self::assertMatchesRegularExpression('/line-height: 32px[^>]*>\s*1\s*</', $html);
        self::assertMatchesRegularExpression('/line-height: 32px[^>]*>\s*2\s*</', $html);
        self::assertStringContainsString('border-radius: 50%', $html);
    }
}
