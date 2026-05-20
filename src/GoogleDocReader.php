<?php

namespace Heorhiev\GoogleDocReader;

use Heorhiev\GoogleDocReader\Dto\GoogleDocReadResult;
use Heorhiev\GoogleDocReader\Support\GoogleDocHtmlFetcher;
use Heorhiev\GoogleDocReader\Support\GoogleDocHtmlSanitizer;

final class GoogleDocReader
{
    public static function readFromUrl(string $url): GoogleDocReadResult
    {
        $documentId = GoogleDocHtmlFetcher::extractDocumentId($url);
        $html = GoogleDocHtmlFetcher::fetchByDocumentId($documentId);

        return GoogleDocHtmlSanitizer::sanitize($html);
    }
}
