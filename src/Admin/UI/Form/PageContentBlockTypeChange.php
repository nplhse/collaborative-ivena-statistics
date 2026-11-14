<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Content\Domain\Enum\PageContentBlockType;

/**
 * Decides what happens to submitted block data when the block type changed.
 *
 * The editor swaps the fields in the browser and asks before content is lost, so normally only fields of the new
 * type arrive. Fields of another type can still arrive (no JavaScript, an outdated page): those are dropped when
 * they are empty or only hold presentation settings, and the type change is refused when they hold content of the
 * original type. This keeps the server from discarding content the editor never showed as lost.
 */
final readonly class PageContentBlockTypeChange
{
    /**
     * @param array<string, mixed> $submittedData
     * @param list<string>         $newTypeFields form fields of the new type
     */
    public static function evaluate(
        PageContentBlockType $originalType,
        PageContentBlockType $newType,
        array $submittedData,
        array $newTypeFields,
    ): self {
        $data = [];
        $lostFields = [];
        $originalContentFields = $originalType->contentFields();

        foreach ($submittedData as $field => $value) {
            if (in_array($field, $newTypeFields, true)) {
                $data[$field] = $value;
                continue;
            }

            if (in_array($field, $originalContentFields, true) && self::hasContent($value)) {
                $lostFields[] = $field;
            }
        }

        if ($originalType !== $newType && [] !== $lostFields) {
            return new self(true, $lostFields, $submittedData);
        }

        return new self(false, [], $data);
    }

    /**
     * @param list<string>         $lostFields
     * @param array<string, mixed> $data
     */
    private function __construct(
        /** True when the block keeps its original type because the change would discard content. */
        public bool $refused,
        public array $lostFields,
        /** Submitted data to use: unchanged when refused, otherwise only fields of the new type. */
        public array $data,
    ) {
    }

    private static function hasContent(mixed $value): bool
    {
        if (is_string($value)) {
            return '' !== trim(strip_tags($value, '<img>'), " \t\n\r\0\x0B\u{A0}");
        }

        if (is_array($value)) {
            return array_any($value, self::hasContent(...));
        }

        return null !== $value && false !== $value;
    }
}
