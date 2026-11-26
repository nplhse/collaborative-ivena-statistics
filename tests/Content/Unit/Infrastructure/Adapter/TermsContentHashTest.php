<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Infrastructure\Adapter;

use App\Content\Infrastructure\Adapter\DoctrineTermsDocumentProvider;
use PHPUnit\Framework\TestCase;

/** The hash stored with an acceptance follows the text of the terms, not the order of JSON object keys (#1564). */
final class TermsContentHashTest extends TestCase
{
    public function testTheHashIsTheSha256OfTheContentWithSortedObjectKeys(): void
    {
        $content = [['type' => 'richtext', 'data' => ['html' => '<p>Ä</p>']]];

        self::assertSame(
            hash('sha256', '[{"data":{"html":"<p>Ä</p>"},"type":"richtext"}]'),
            DoctrineTermsDocumentProvider::contentHash($content),
        );
    }

    public function testTheOrderOfObjectKeysDoesNotChangeTheHash(): void
    {
        self::assertSame(
            DoctrineTermsDocumentProvider::contentHash([['type' => 'richtext', 'data' => ['html' => '<p>A</p>', 'x' => 1]]]),
            DoctrineTermsDocumentProvider::contentHash([['data' => ['x' => 1, 'html' => '<p>A</p>'], 'type' => 'richtext']]),
        );
    }

    public function testChangedTextAndChangedBlockOrderChangeTheHash(): void
    {
        $first = ['type' => 'richtext', 'data' => ['html' => '<p>A</p>']];
        $second = ['type' => 'richtext', 'data' => ['html' => '<p>B</p>']];

        $hash = DoctrineTermsDocumentProvider::contentHash([$first, $second]);

        self::assertNotSame($hash, DoctrineTermsDocumentProvider::contentHash([$second, $first]));
        self::assertNotSame($hash, DoctrineTermsDocumentProvider::contentHash([$first, ['type' => 'richtext', 'data' => ['html' => '<p>C</p>']]]));
    }
}
