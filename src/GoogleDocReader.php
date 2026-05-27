<?php

namespace adsytech\GoogleDocReader;

use adsytech\GoogleDocReader\Dto\GoogleDocReadResult;
use adsytech\GoogleDocReader\Support\GoogleDocHtmlFetcher;
use adsytech\GoogleDocReader\Support\GoogleDocHtmlSanitizer;

final class GoogleDocReader
{
    public static function readFromUrl(string $url): GoogleDocReadResult
    {
        $documentId = GoogleDocHtmlFetcher::extractDocumentId($url);
        $html = GoogleDocHtmlFetcher::fetchByDocumentId($documentId);

        return GoogleDocHtmlSanitizer::sanitize($html);
    }
}
