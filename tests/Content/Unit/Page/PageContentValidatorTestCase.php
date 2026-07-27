<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class PageContentValidatorTestCase extends TestCase
{
    protected function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $id = (string) $id;

                $messages = [
                    'page.validation.content_must_be_array' => 'Content must be a list of blocks.',
                    'page.validation.block_must_be_object' => 'must be an object.',
                    'page.validation.block_type_required' => 'field "type" is required.',
                    'page.validation.block_unknown_type' => 'unknown block type "{type}".',
                    'page.validation.block_data_must_be_object' => 'field "data" must be an object.',
                    'page.validation.block_enabled_must_be_bool' => 'field "enabled" must be true or false.',
                    'page.validation.block_required_field' => 'data.{field} is required.',
                    'page.validation.image_src_or_media_required' => 'image src or media required.',
                    'page.validation.image_invalid_size' => 'invalid image size.',
                    'page.validation.image_invalid_float' => 'invalid float option.',
                    'page.validation.accordion_items_required' => 'At least one accordion item is required.',
                    'page.validation.accordion_item_must_be_object' => 'must be an object.',
                    'page.validation.image_float_requires_non_full_width' => 'Text wrap requires a non-full-width image.',
                    'page.validation.highlight_icon_required' => 'A custom icon is required when icon mode is custom.',
                    'page.validation.headline_invalid_level' => 'invalid headline level.',
                    'page.validation.headline_invalid_align' => 'invalid headline alignment.',
                    'page.validation.headline_invalid_spacing' => 'invalid {field} spacing.',
                    'page.validation.highlight_invalid_variant' => 'invalid highlight variant.',
                    'page.validation.highlight_invalid_icon_mode' => 'invalid icon mode.',
                    'page.validation.cta_media_required' => 'media library PDF is required.',
                ];

                $message = $messages[$id] ?? $id;

                foreach ($parameters as $name => $value) {
                    $message = str_replace((string) $name, (string) $value, $message);
                }

                return $message;
            }

            #[\Override]
            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
