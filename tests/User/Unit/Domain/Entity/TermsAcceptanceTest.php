<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** An acceptance keeps what was accepted, by whom and when (#1564). */
final class TermsAcceptanceTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testItKeepsAccountVersionLanguageAndHash(): void
    {
        $user = new User();
        $acceptance = new TermsAcceptance($user, TermsAcceptanceKind::Terms, '2026-12', 'de', str_repeat('a', 64), new \DateTimeImmutable('2026-12-02 09:30:00'));

        self::assertNull($acceptance->getId(), 'not persisted');
        self::assertSame($user, $acceptance->getUser());
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
    }
}
