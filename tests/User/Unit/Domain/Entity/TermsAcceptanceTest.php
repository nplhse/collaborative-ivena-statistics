<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** An acceptance keeps what was accepted, by whom and when (#1564), and for which hospital (#1565). */
final class TermsAcceptanceTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testItKeepsAccountKindVersionLanguageAndHash(): void
    {
        $user = new User();
        $acceptance = new TermsAcceptance($user, TermsAcceptanceKind::Terms, '2026-12', 'de', str_repeat('a', 64), new \DateTimeImmutable('2026-12-02 09:30:00'));

        self::assertNull($acceptance->getId(), 'not persisted');
        self::assertSame($user, $acceptance->getUser());
        // assertEquals: PHPStan reports assertSame of the only enum case as always true.
        self::assertEquals(TermsAcceptanceKind::Terms, $acceptance->getKind());
        self::assertSame('2026-12', $acceptance->getTermsVersion());
        self::assertSame('de', $acceptance->getLocale());
        self::assertSame(str_repeat('a', 64), $acceptance->getContentHash());
        self::assertSame('2026-12-02 09:30:00', $acceptance->getAcceptedAt()->format('Y-m-d H:i:s'));
    }

    public function testWithoutAnExplicitTimeItIsAcceptedNow(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-12-03 08:15:00'));

        $acceptance = new TermsAcceptance(new User(), TermsAcceptanceKind::Terms, null, 'en', null);

        self::assertSame('2026-12-03 08:15:00', $acceptance->getAcceptedAt()->format('Y-m-d H:i:s'));
        self::assertNull($acceptance->getTermsVersion());
        self::assertNull($acceptance->getContentHash());
        self::assertNull($acceptance->getHospital());
    }

    public function testAnUploadAuthorityNamesItsHospital(): void
    {
        $hospital = new Hospital();
        $acceptance = new TermsAcceptance(new User(), TermsAcceptanceKind::UploadAuthority, '2026-12', 'de', null, null, $hospital);

        self::assertSame($hospital, $acceptance->getHospital());
    }

    public function testAnUploadAuthorityWithoutHospitalIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TermsAcceptance(new User(), TermsAcceptanceKind::UploadAuthority, '2026-12', 'de', null);
    }

    public function testTheTermsOfUseTakeNoHospital(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TermsAcceptance(new User(), TermsAcceptanceKind::Terms, '2026-12', 'de', null, null, new Hospital());
    }
}
