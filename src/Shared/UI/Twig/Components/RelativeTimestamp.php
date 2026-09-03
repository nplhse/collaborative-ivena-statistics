<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use App\Shared\Application\DateTime\FormattedRelativeTimestamp;
use App\Shared\Application\DateTime\RelativeTimestampFormatter;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'RelativeTimestamp', template: '@Shared/components/RelativeTimestamp.html.twig')]
final class RelativeTimestamp
{
    /** @psalm-suppress PropertyNotSetInConstructor Hydrated by Twig Component attributes. */
    public \DateTimeInterface $datetime;

    public string $class = 'text-secondary small';

    private ?FormattedRelativeTimestamp $view = null;

    private ?string $describedById = null;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private readonly RelativeTimestampFormatter $formatter,
    ) {
    }

    public function getView(): FormattedRelativeTimestamp
    {
        return $this->view ??= $this->formatter->format($this->datetime);
    }

    public function getDescribedById(): string
    {
        return $this->describedById ??= 'relative-ts-'.bin2hex(random_bytes(4));
    }
}
