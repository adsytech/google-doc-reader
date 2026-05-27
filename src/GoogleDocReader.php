<?php

namespace Adsytech\GoogleDocReader;

use Adsytech\GoogleDocReader\Dto\GoogleDocReadResult;
use Adsytech\GoogleDocReader\Support\GoogleDocHtmlFetcher;
use Adsytech\GoogleDocReader\Support\GoogleDocHtmlSanitizer;

final class GoogleDocReader
{
    public static function readFromUrl(string $url): GoogleDocReadResult
    {
        $documentId = GoogleDocHtmlFetcher::extractDocumentId($url);
        $html = GoogleDocHtmlFetcher::fetchByDocumentId($documentId);

        return GoogleDocHtmlSanitizer::sanitize($html);
    }
}
