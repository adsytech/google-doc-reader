<?php

namespace Heorhiev\GoogleDocReader\Support;

use InvalidArgumentException;
use RuntimeException;

final class GoogleDocHtmlFetcher
{
    private const REQUEST_TIMEOUT = 90;
    private const REQUEST_CONNECT_TIMEOUT = 15;
    private const REQUEST_RETRY_COUNT = 3;
    private const REQUEST_RETRY_DELAY_USEC = 300000;
    private const USER_AGENT = 'GoogleDocReader/1.0';

    public static function extractDocumentId(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('Google Docs URL is required.');
        }

        if (!preg_match('~https?://docs\.google\.com/document/d/([a-zA-Z0-9_-]+)~i', $url, $matches)) {
            throw new InvalidArgumentException('Unsupported Google Docs URL format.');
        }

        return $matches[1];
    }

    public static function fetchByDocumentId(string $documentId): string
    {
        $url = sprintf('https://docs.google.com/document/d/%s/export?format=html', rawurlencode($documentId));
        [$statusCode, $body] = self::performRequestWithRetry($url);

        if ($statusCode >= 400) {
            throw new RuntimeException(self::buildGoogleDocsHttpErrorMessage($statusCode));
        }

        if (trim($body) === '') {
            throw new RuntimeException('Google Docs export returned empty HTML.');
        }

        return $body;
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private static function performRequest(string $url): array
    {
        $curlHandle = curl_init($url);

        if ($curlHandle === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,image/*,*/*;q=0.8'],
        ]);

        $body = curl_exec($curlHandle);
        $statusCode = (int)curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($curlHandle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($curlHandle);
        curl_close($curlHandle);

        if ($body === false) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed.');
        }

        return [$statusCode, $body, $contentType];
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private static function performRequestWithRetry(string $url): array
    {
        $lastResponse = [0, '', ''];
        $lastError = '';

        for ($attempt = 1; $attempt <= self::REQUEST_RETRY_COUNT; $attempt++) {
            try {
                $lastResponse = self::performRequest($url);
                [$statusCode] = $lastResponse;

                if ($statusCode < 500 || $statusCode >= 600) {
                    return $lastResponse;
                }
            } catch (\Throwable $throwable) {
                $lastError = $throwable->getMessage();

                if (!self::isRetriableRequestError($throwable)) {
                    throw $throwable;
                }
            }

            if ($attempt < self::REQUEST_RETRY_COUNT) {
                usleep(self::REQUEST_RETRY_DELAY_USEC);
            }
        }

        if ($lastError !== '') {
            throw new RuntimeException(self::buildTransportErrorMessage($lastError));
        }

        return $lastResponse;
    }

    private static function buildGoogleDocsHttpErrorMessage(int $statusCode): string
    {
        return match ($statusCode) {
            401, 403 => 'Google Docs denied access. Make sure the document is shared so it can be exported.',
            404 => 'Google Docs document was not found. Check the document URL.',
            500, 502, 503, 504 => 'Google Docs export is temporarily unavailable. Try again in a moment.',
            default => sprintf('Google Docs returned HTTP %d.', $statusCode),
        };
    }

    private static function buildTransportErrorMessage(string $error): string
    {
        if (self::isTimeoutErrorMessage($error)) {
            return 'Google Docs export timed out. Try again in a moment or use a smaller document.';
        }

        return $error !== '' ? $error : 'Google Docs request failed.';
    }

    private static function isRetriableRequestError(\Throwable $throwable): bool
    {
        return self::isTimeoutErrorMessage($throwable->getMessage());
    }

    private static function isTimeoutErrorMessage(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout was reached')
            || str_contains($message, 'operation timed out');
    }
}
