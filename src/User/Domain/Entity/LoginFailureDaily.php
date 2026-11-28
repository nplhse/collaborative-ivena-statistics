<?php

declare(strict_types=1);

namespace App\User\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sign-in failures of one Europe/Berlin calendar day (#1487), folded from LoginFailureRecord by the daily job.
 *
 * A day has a row only when its failures could be counted, also when there were none. Attempts are failures plus
 * refused attempts; the distinct counts and the two maxima look at attempts only. The maxima show the patterns
 * operators look for: one username tried from many IPs, and one IP trying many usernames.
 */
#[ORM\Entity]
#[ORM\Table(name: 'login_failure_daily')]
#[ORM\UniqueConstraint(name: 'uniq_login_failure_daily_date', columns: ['date'])]
class LoginFailureDaily
{
    /** Single failures are deleted after this many days. */
    public const int EVENT_RETENTION_DAYS = 30;

    /** Daily rows are deleted after this many days. */
    public const int DAILY_RETENTION_DAYS = 365;

    /** A day is flagged when one username was tried from at least this many IPs. */
    public const int MANY_IPS_PER_IDENTIFIER = 10;

    /** A day is flagged when one IP tried at least this many usernames. */
    public const int MANY_IDENTIFIERS_PER_IP = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column]
        private int $failures,
        #[ORM\Column]
        private int $refused,
        #[ORM\Column]
        private int $lockThresholdsReached,
        #[ORM\Column]
        private int $accountsLocked,
        #[ORM\Column]
        private int $distinctIdentifiers,
        #[ORM\Column]
        private int $distinctIps,
        #[ORM\Column]
        private int $maxIpsPerIdentifier,
        #[ORM\Column]
        private int $maxIdentifiersPerIp,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    /**
     * Finished days to write: from the day after the last daily row (or the day of the first failure) up to yesterday,
     * but none older than single failures are kept. Yesterday is always included, so a second run on the same day
     * gives the same row.
     *
     * @param \DateTimeImmutable $today start of today
     *
     * @return list<\DateTimeImmutable>
     */
    public static function daysToFold(?\DateTimeImmutable $lastAggregated, ?\DateTimeImmutable $firstEventDay, \DateTimeImmutable $today): array
    {
        $oneDay = new \DateInterval('P1D');
        $yesterday = $today->sub($oneDay);
        $oldestKept = $today->sub(new \DateInterval(\sprintf('P%dD', self::EVENT_RETENTION_DAYS)));
        $first = $lastAggregated?->add($oneDay) ?? $firstEventDay ?? $yesterday;
        $first = min(max($first, $oldestKept), $yesterday);

        $days = [];
        for ($day = $first; $day < $today; $day = $day->add($oneDay)) {
            $days[] = $day;
        }

        return $days;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getFailures(): int
    {
        return $this->failures;
    }

    public function getRefused(): int
    {
        return $this->refused;
    }

    public function getLockThresholdsReached(): int
    {
        return $this->lockThresholdsReached;
    }

    public function getAccountsLocked(): int
    {
        return $this->accountsLocked;
    }

    public function getDistinctIdentifiers(): int
    {
        return $this->distinctIdentifiers;
    }

    public function getDistinctIps(): int
    {
        return $this->distinctIps;
    }

    public function getMaxIpsPerIdentifier(): int
    {
        return $this->maxIpsPerIdentifier;
    }

    public function getMaxIdentifiersPerIp(): int
    {
        return $this->maxIdentifiersPerIp;
    }

    public function hasIdentifierFromManyIps(): bool
    {
        return $this->maxIpsPerIdentifier >= self::MANY_IPS_PER_IDENTIFIER;
    }

    public function hasIpAgainstManyIdentifiers(): bool
    {
        return $this->maxIdentifiersPerIp >= self::MANY_IDENTIFIERS_PER_IP;
    }

    public function isFlagged(): bool
    {
        return $this->hasIdentifierFromManyIps() || $this->hasIpAgainstManyIdentifiers();
    }
}
