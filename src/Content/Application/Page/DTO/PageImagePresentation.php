<?php

declare(strict_types=1);

namespace App\Content\Application\Page\DTO;

final readonly class PageImagePresentation
{
    /**
     * @param list<string> $figureClasses
     */
    public function __construct(
        public array $figureClasses,
        public ?int $imgWidth = null,
        public ?int $imgHeight = null,
    ) {
    }

    public function figureClassString(): string
    {
        return implode(' ', $this->figureClasses);
    }
}
