<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AdminMenuTranslationTest extends WebTestCase
{
    use Factories;

    private const array ENGLISH_ONLY_LABELS = [
        'Back to frontend',
        'Management',
        'Hospital Access',
        'Onboarding steps',
        'Allocations',
        'Assignments',
        'Dispatch Areas',
        'Indication Normalized',
        'MCI Cases',
        'Secondary Transports',
        'Specialities',
        'Import Rejects',
        'Batch Activities',
        'Explorer views',
        'Cookie consents',
        'Failed messages',
        'Monthly reminders',
    ];

    public function testMenuIsGermanForAGermanAdmin(): void
    {
        $menu = $this->menuText('de');

        foreach ([
            'Zurück zur Anwendung',
            'Verwaltung',
            'Zugriffsberechtigungen',
            'Zuweisungen',
            'Zuweisungstypen',
            'Leitstellenbereiche',
            'MANV-Fälle',
            'Fachrichtungen',
            'Abgelehnte Importzeilen',
            'Explorer-Ansichten',
            'Inhalte',
            'Fehlgeschlagene Nachrichten',
            'Monatliche Erinnerungen',
        ] as $label) {
            self::assertStringContainsString($label, $menu);
        }

        foreach (self::ENGLISH_ONLY_LABELS as $label) {
            self::assertStringNotContainsString($label, $menu);
        }
    }

    public function testMenuStaysEnglishForAnEnglishAdmin(): void
    {
        $menu = $this->menuText('en');

        foreach (self::ENGLISH_ONLY_LABELS as $label) {
            self::assertStringContainsString($label, $menu);
        }
    }

    private function menuText(string $locale): string
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create(['locale' => $locale]));

        $crawler = $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();

        $menu = $crawler->filter('#main-menu');
        self::assertCount(1, $menu);

        return $menu->text();
    }
}
