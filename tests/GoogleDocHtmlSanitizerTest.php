<?php

namespace Adsytech\GoogleDocReader\Tests;

use Adsytech\GoogleDocReader\Support\GoogleDocHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class GoogleDocHtmlSanitizerTest extends TestCase
{
    public function testSanitizeRemovesRedundantSpansAndLeftAlignment(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p><span><span style="text-align: left;">Hello</span></span></p></body></html>'
        );

        self::assertSame('Doc', $result->title);
        self::assertSame('<p>Hello</p>', $result->html);
    }

    public function testSanitizeConvertsBoldSpanToStrongWithoutExtraSpan(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p><span style="font-weight: 700;">Bold text</span></p></body></html>'
        );

        self::assertSame('<p><strong>Bold text</strong></p>', $result->html);
    }

    public function testSanitizeKeepsRemainingAllowedStylesWhenApplyingWrappers(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p><span style="font-weight: 700; color: #ff0000;">Styled text</span></p></body></html>'
        );

        self::assertSame('<p><strong><span style="color: #ff0000">Styled text</span></strong></p>', $result->html);
    }

    public function testSanitizeRemovesEmptyAnchorWithWhitespaceOnly(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p>Text<a href="https://example.com">&nbsp;</a></p></body></html>'
        );

        self::assertSame('<p>Text</p>', $result->html);
    }

    public function testSanitizeUnwrapsUnderlineAroundLinkOnlyContent(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p>A<u><span style="color: #1155CC"><a href="https://example.com">Tesla</a></span></u></p></body></html>'
        );

        self::assertSame('<p>A<a href="https://example.com">Tesla</a></p>', $result->html);
    }

    public function testSanitizeRemovesStrongWrapperInsideHeading(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><h2><strong>Heading title</strong></h2></body></html>'
        );

        self::assertSame('<h2>Heading title</h2>', $result->html);
    }

    public function testSanitizeUnwrapsGoogleRedirectLinks(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p><a href="https://www.google.com/url?q=https%3A%2F%2Fwww.leaselab.com.au%2Fcar%2Ftesla&amp;sa=D&amp;source=editors">Tesla</a></p></body></html>'
        );

        self::assertSame('<p><a href="https://www.leaselab.com.au/car/tesla">Tesla</a></p>', $result->html);
    }

    public function testSanitizeKeepsRemoteImageSourcesWithoutImporting(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p><img src="https://example.com/image.png" alt="Image" title="Title"></p></body></html>'
        );

        self::assertSame('<p><img src="https://example.com/image.png" alt="Image" title="Title"></p>', $result->html);
    }

    public function testSanitizeKeepsEmbeddedImageSourcesWithoutImporting(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p><img src="data:image/png;base64,AAAA" alt="Image"></p></body></html>'
        );

        self::assertSame('<p><img src="data:image/png;base64,AAAA" alt="Image"></p>', $result->html);
    }

    public function testSanitizeRemovesUnsafeImageSources(): void
    {
        $result = GoogleDocHtmlSanitizer::sanitize(
            '<html><head><title>Doc</title></head><body><p>Before<img src="javascript:alert(1)">After</p></body></html>'
        );

        self::assertSame('<p>BeforeAfter</p>', $result->html);
    }
}
