<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PageContentValidator
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /** @var array<string, list<string>> */
    private const array REQUIRED_FIELDS = [
        'richtext' => ['html'],
        'image' => ['src', 'alt'],
        'cta' => ['headline', 'buttonLabel', 'buttonUrl'],
        'quote' => ['text'],
    ];

    /**
     * @return list<string>
     */
    public function validate(mixed $content): array
    {
        $errors = [];

        if (!is_array($content)) {
            return [$this->translator->trans('page.validation.content_must_be_array', [], 'validators')];
        }

        foreach ($content as $index => $block) {
            $prefix = sprintf('Block %d', (int) $index + 1);

            if (!is_array($block)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_must_be_object', [], 'validators'));
                continue;
            }

            $type = $block['type'] ?? null;
            if (!is_string($type) || '' === trim($type)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_type_required', [], 'validators'));
                continue;
            }

            if (!array_key_exists($type, self::REQUIRED_FIELDS)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_unknown_type', ['{type}' => $type], 'validators'));
                continue;
            }

            $data = $block['data'] ?? null;
            if (!is_array($data)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_data_must_be_object', [], 'validators'));
                continue;
            }

            if (array_key_exists('enabled', $block) && !is_bool($block['enabled'])) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_enabled_must_be_bool', [], 'validators'));
            }

            foreach (self::REQUIRED_FIELDS[$type] as $fieldName) {
                $value = $data[$fieldName] ?? null;
                if (!is_string($value) || '' === trim($value)) {
                    $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => $fieldName], 'validators'));
                }
            }
        }

        return $errors;
    }

    public function assertValid(mixed $content): void
    {
        $errors = $this->validate($content);

        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
    }
}
