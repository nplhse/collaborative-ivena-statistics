<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Terms;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Application\Contract\TermsDocumentProviderInterface;
use App\User\Application\Terms\TermsAcceptanceFactory;
use App\User\Application\Terms\TermsDocument;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Which version, language and content hash an acceptance gets (#1564) and an upload authority (#1565). That the
 * entity keeps them is in `TermsAcceptanceTest`.
 */
#[CoversClass(TermsAcceptanceFactory::class)]
final class TermsAcceptanceFactoryTest extends TestCase
{
    use ClockSensitiveTrait;

    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCurrentTermsTakeTheVersionAndThePublishedDocument(): void
    {
        self::mockTime('2026-12-02 09:30:00');
        // Asked for French, the page falls back to German; the acceptance stores the language actually shown.
        $factory = $this->factory('2026-12', new TermsDocument('de', self::HASH));

        $acceptance = $factory->forCurrentTerms(new User(), 'fr');

        self::assertSame(
            ['2026-12', 'de', self::HASH, '2026-12-02 09:30:00', null],
            $this->summary($acceptance),
        );
    }

    public function testCurrentTermsWithoutAPublishedPageKeepTheRequestedLanguageAndNoHash(): void
    {
        self::mockTime('2026-12-02 09:30:00');
        $factory = $this->factory('2026-12', null);

        $acceptance = $factory->forCurrentTerms(new User(), 'en');

        self::assertSame(['2026-12', 'en', null, '2026-12-02 09:30:00', null], $this->summary($acceptance));
    }

    public function testCurrentTermsWithoutAConfiguredVersionAreStoredWithoutOne(): void
    {
        $factory = $this->factory(null, new TermsDocument('de', self::HASH));

        $acceptance = $factory->forCurrentTerms(new User(), 'de');

        self::assertNull($acceptance->getTermsVersion());
    }

    public function testUploadAuthorityNowTakesTheCurrentVersionAndDocument(): void
    {
        self::mockTime('2026-12-02 09:30:00');
        $factory = $this->factory('2026-12', new TermsDocument('de', self::HASH));
        $hospital = new Hospital()->setName('Klinikum Musterstadt');

        $acceptance = $factory->forUploadAuthority(new User(), $hospital, 'de');

        self::assertSame(['2026-12', 'de', self::HASH, '2026-12-02 09:30:00', 'Klinikum Musterstadt'], $this->summary($acceptance));
    }

    public function testEarlierConfirmationKeepsItsVersionAndTimeAndSkipsTheCurrentDocument(): void
    {
        // An assignment request confirmed under version 2026-06 and approved after the terms moved on to 2026-12.
        $factory = $this->factory('2026-12', new TermsDocument('de', self::HASH));
        $hospital = new Hospital()->setName('Klinikum Musterstadt');

        $acceptance = $factory->forUploadAuthority(
            new User(),
            $hospital,
            'en',
            new \DateTimeImmutable('2026-11-20 14:00:00'),
            '2026-06',
        );

        // The page published today is not what the user saw then, so neither its language nor its hash is stored.
        self::assertSame(['2026-06', 'en', null, '2026-11-20 14:00:00', 'Klinikum Musterstadt'], $this->summary($acceptance));
    }

    private function factory(?string $version, ?TermsDocument $document): TermsAcceptanceFactory
    {
        $documents = $this->createStub(TermsDocumentProviderInterface::class);
        $documents->method('current')->willReturn($document);

        return new TermsAcceptanceFactory(new TermsVersion($version), $documents);
    }

    /**
     * @return array{?string, string, ?string, string, ?string} version, locale, content hash, accepted at, hospital
     */
    private function summary(TermsAcceptance $acceptance): array
    {
        return [
            $acceptance->getTermsVersion(),
            $acceptance->getLocale(),
            $acceptance->getContentHash(),
            $acceptance->getAcceptedAt()->format('Y-m-d H:i:s'),
            $acceptance->getHospital()?->getName(),
        ];
    }
}
