<?php

namespace adsytech\GoogleDocReader\Tests;

use adsytech\GoogleDocReader\Support\GoogleDocHtmlFetcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GoogleDocHtmlFetcherTest extends TestCase
{
    public function testExtractDocumentIdFromGoogleDocsUrl(): void
    {
        self::assertSame(
            'abc_123-DEF',
            GoogleDocHtmlFetcher::extractDocumentId('https://docs.google.com/document/d/abc_123-DEF/edit')
        );
    }

    public function testExtractDocumentIdRejectsUnsupportedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GoogleDocHtmlFetcher::extractDocumentId('https://example.com/document/d/abc');
    }
}
