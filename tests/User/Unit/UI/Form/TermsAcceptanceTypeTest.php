<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\UI\Form;

use App\User\UI\Form\TermsAcceptanceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * The acceptance form at login (#1564, #1565): the terms must be accepted, the renewal of the upload authority is
 * offered only for hospitals confirmed under an earlier version and is optional. That the page answers 422 and stores
 * nothing without the acceptance, and stores no renewal when the box stays empty, is in `TermsAcceptanceAtLoginTest`.
 */
#[CoversClass(TermsAcceptanceType::class)]
final class TermsAcceptanceTypeTest extends TestCase
{
    /** @return iterable<string, array{list<string>, array<string, string>, bool}> */
    public static function submissions(): iterable
    {
        // hospitals to renew, submitted fields, valid
        yield 'terms accepted' => [[], ['accept' => '1'], true];
        yield 'terms not accepted' => [[], [], false];
        yield 'terms accepted, renewal left empty' => [['Klinikum Nord'], ['accept' => '1'], true];
        yield 'terms accepted, renewal ticked' => [['Klinikum Nord'], ['accept' => '1', 'uploadAuthority' => '1'], true];
        yield 'only the renewal ticked' => [['Klinikum Nord'], ['uploadAuthority' => '1'], false];
    }

    /**
     * @param list<string>          $hospitals
     * @param array<string, string> $fields
     */
    #[DataProvider('submissions')]
    public function testTheTermsMustBeAcceptedAndTheRenewalIsOptional(array $hospitals, array $fields, bool $valid): void
    {
        $form = $this->formFactory()->create(TermsAcceptanceType::class, null, ['upload_authority_hospitals' => $hospitals]);

        $form->submit($fields);

        self::assertSame($valid, $form->isValid());
    }

    public function testAMissingAcceptanceNamesTheReason(): void
    {
        $form = $this->formFactory()->create(TermsAcceptanceType::class);

        $form->submit([]);

        $error = $form->get('accept')->getErrors()[0] ?? null;
        self::assertInstanceOf(FormError::class, $error);
        self::assertSame('validation.terms.accept_required', $error->getMessageTemplate());
    }

    public function testTheRenewalNamesTheHospitalsConfirmedEarlier(): void
    {
        $form = $this->formFactory()->create(TermsAcceptanceType::class, null, ['upload_authority_hospitals' => ['Klinikum Nord', 'Klinikum Süd']]);

        $parameters = $form->get('uploadAuthority')->getConfig()->getOption('label_translation_parameters');

        self::assertSame(['hospitals' => 'Klinikum Nord, Klinikum Süd'], $parameters);
    }

    public function testWithoutHospitalsConfirmedEarlierNoRenewalIsOffered(): void
    {
        $form = $this->formFactory()->create(TermsAcceptanceType::class);

        self::assertFalse($form->has('uploadAuthority'));
    }

    private function formFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
