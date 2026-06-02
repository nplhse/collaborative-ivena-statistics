<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Domain\Entity\Media;
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

            $data = $block['data'] ?? null;
            if (!is_array($data)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_data_must_be_object', [], 'validators'));
                continue;
            }

            if (array_key_exists('enabled', $block) && !is_bool($block['enabled'])) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_enabled_must_be_bool', [], 'validators'));
            }

            $errors = array_merge($errors, $this->validateBlock($prefix, $type, $data));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateBlock(string $prefix, string $type, array $data): array
    {
        if ('image' === $type) {
            return $this->validateImageBlock($prefix, $data);
        }

        if ('cta' === $type) {
            return $this->validateCtaBlock($prefix, $data);
        }

        if (!array_key_exists($type, self::REQUIRED_FIELDS)) {
            return [sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_unknown_type', ['{type}' => $type], 'validators'))];
        }

        $errors = [];
        foreach (self::REQUIRED_FIELDS[$type] as $fieldName) {
            if (!$this->isNonEmptyString($data[$fieldName] ?? null)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => $fieldName], 'validators'));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateImageBlock(string $prefix, array $data): array
    {
        $errors = [];

        if (!$this->isNonEmptyString($data['alt'] ?? null)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'alt'], 'validators'));
        }

        $hasMedia = $this->hasMediaReference($data['mediaId'] ?? null);
        $hasSrc = $this->isNonEmptyString($data['src'] ?? null);

        if (!$hasMedia && !$hasSrc) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.image_src_or_media_required', [], 'validators'));
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function validateCtaBlock(string $prefix, array $data): array
    {
        $errors = [];

        foreach (['headline', 'buttonLabel'] as $fieldName) {
            if (!$this->isNonEmptyString($data[$fieldName] ?? null)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => $fieldName], 'validators'));
            }
        }

        $linkType = (string) ($data['linkType'] ?? 'url');

        if ('media' === $linkType) {
            if (!$this->hasMediaReference($data['mediaId'] ?? null)) {
                $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.cta_media_required', [], 'validators'));
            }
        } elseif (!$this->isNonEmptyString($data['buttonUrl'] ?? null)) {
            $errors[] = sprintf('%s: %s', $prefix, $this->translator->trans('page.validation.block_required_field', ['{field}' => 'buttonUrl'], 'validators'));
        }

        return $errors;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && '' !== trim($value);
    }

    private function hasMediaReference(mixed $value): bool
    {
        if ($value instanceof Media) {
            return null !== $value->getId();
        }

        if (is_int($value) && $value > 0) {
            return true;
        }

        return is_string($value) && '' !== $value && ctype_digit($value);
    }

    public function assertValid(mixed $content): void
    {
        $errors = $this->validate($content);

        if ([] !== $errors) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
    }
}
