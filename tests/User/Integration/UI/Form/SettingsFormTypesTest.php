<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\UI\Form;

use App\User\Domain\Entity\User;
use App\User\UI\Form\SettingsLocaleType;
use App\User\UI\Form\SettingsProfileType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The forms of the account settings page on their own; the page itself is SettingsControllerTest (#1348).
 */
final class SettingsFormTypesTest extends KernelTestCase
{
    /** The language that applies without a choice of the user is marked; languages are named in their own language. */
    public function testTheAutomaticDefaultLanguageIsMarkedInItsLabel(): void
    {
        $form = $this->form(SettingsLocaleType::class, ['automatic_default_locale' => 'de']);

        $labels = array_map(
            static fn (ChoiceView $choice): string => (string) $choice->label,
            $form->get('locale')->createView()->vars['choices'],
        );

        self::assertSame(['English', 'Deutsch (automatic default)'], array_values($labels));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function displayNameLengths(): iterable
    {
        yield 'at the limit' => [User::DISPLAY_NAME_MAX_LENGTH, true];
        yield 'one character too long' => [User::DISPLAY_NAME_MAX_LENGTH + 1, false];
    }

    #[DataProvider('displayNameLengths')]
    public function testTheDisplayNameIsLimitedInLength(int $length, bool $valid): void
    {
        $form = $this->form(SettingsProfileType::class);

        $form->submit(['displayName' => str_repeat('a', $length)]);

        self::assertSame($valid, $form->isValid());
    }

    /**
     * @param class-string         $type
     * @param array<string, mixed> $options
     *
     * @return FormInterface<mixed>
     */
    private function form(string $type, array $options = []): FormInterface
    {
        self::bootKernel();

        return self::getContainer()->get(FormFactoryInterface::class)->create($type, null, ['csrf_protection' => false] + $options);
    }
}
