<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Page;

use App\Content\Application\Page\PageContentValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class PageContentValidatorTest extends TestCase
{
    public function testValidateBlockAtUsesTheGivenPositionInMessages(): void
    {
        $validator = new PageContentValidator(new IdentityTranslator());

        $errors = $validator->validateBlockAt(3, ['type' => 'image', 'enabled' => true, 'data' => ['alt' => 'Alt']]);

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Block 3: ', $errors[0]);
    }

    public function testValidateBlockAtAcceptsAValidBlock(): void
    {
        $validator = new PageContentValidator(new IdentityTranslator());

        self::assertSame([], $validator->validateBlockAt(1, ['type' => 'quote', 'data' => ['text' => 'Quote']]));
    }

    public function testValidateNumbersBlocksByTheirPlaceInTheList(): void
    {
        $validator = new PageContentValidator(new IdentityTranslator());

        $errors = $validator->validate([
            ['type' => 'quote', 'data' => ['text' => 'Valid']],
            ['type' => 'quote', 'data' => ['text' => '']],
        ]);

        self::assertCount(1, $errors);
        self::assertStringStartsWith('Block 2: ', $errors[0]);
    }
}
