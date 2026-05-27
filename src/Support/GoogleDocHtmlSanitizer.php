<?php

namespace Adsytech\GoogleDocReader\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Adsytech\GoogleDocReader\Dto\GoogleDocReadResult;
use RuntimeException;

final class GoogleDocHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'blockquote', 'br', 'code', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
        'img', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong', 'sub', 'sup', 'table', 'tbody',
        'td', 'th', 'thead', 'tr', 'u', 'ul',
    ];
    private const CLASS_STYLE_PROPERTIES = [
        'background-color',
        'color',
        'font-style',
        'font-weight',
        'text-align',
        'text-decoration',
        'vertical-align',
    ];

    public static function sanitize(string $html): GoogleDocReadResult
    {
        $sourceDom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $sourceDom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($sourceDom);
        $body = $xpath->query('//body')->item(0);

        if (!$body instanceof DOMElement) {
            throw new RuntimeException('Google Docs export does not contain a body.');
        }

        $classStyles = self::extractClassStyles($xpath);
        $resultDom = new DOMDocument('1.0', 'UTF-8');
        $wrapper = $resultDom->createElement('div');
        $resultDom->appendChild($wrapper);

        foreach ($body->childNodes as $childNode) {
            self::appendSanitizedNode($wrapper, $childNode, $resultDom, $classStyles);
        }

        $title = trim((string)$xpath->evaluate('string(//head/title)'));
        $resultHtml = trim(self::innerHtml($wrapper));

        if ($resultHtml === '') {
            throw new RuntimeException('Google Doc Content is empty after sanitization.');
        }

        return new GoogleDocReadResult(
            html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $resultHtml
        );
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function extractClassStyles(DOMXPath $xpath): array
    {
        $styles = [];

        foreach ($xpath->query('//style') as $styleNode) {
            $css = (string)$styleNode->textContent;

            if ($css === '') {
                continue;
            }

            preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $styles[$match[1]] = self::parseStyleDeclarations($match[2], self::CLASS_STYLE_PROPERTIES);
            }
        }

        return $styles;
    }

    /**
     * @param array<string, array<string, string>> $classStyles
     */
    private static function appendSanitizedNode(
        DOMNode $parentNode,
        DOMNode $sourceNode,
        DOMDocument $targetDom,
        array $classStyles
    ): void
    {
        $sanitizedNode = self::sanitizeNode($sourceNode, $targetDom, $classStyles);

        if ($sanitizedNode !== null) {
            $parentNode->appendChild($sanitizedNode);
        }
    }

    /**
     * @param array<string, array<string, string>> $classStyles
     */
    private static function sanitizeNode(
        DOMNode $node,
        DOMDocument $targetDom,
        array $classStyles
    ): ?DOMNode
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $targetDom->createTextNode($node->textContent);
        }

        if (!$node instanceof DOMElement) {
            return null;
        }

        $tagName = strtolower($node->tagName);

        if (in_array($tagName, ['body', 'head', 'html'], true)) {
            $fragment = $targetDom->createDocumentFragment();

            foreach ($node->childNodes as $childNode) {
                self::appendSanitizedNode($fragment, $childNode, $targetDom, $classStyles);
            }

            return $fragment;
        }

        if (in_array($tagName, ['meta', 'link', 'script', 'style', 'title'], true)) {
            return null;
        }

        $tagName = match ($tagName) {
            'article', 'section' => 'div',
            'b' => 'strong',
            'i' => 'em',
            default => $tagName,
        };

        if (!in_array($tagName, self::ALLOWED_TAGS, true)) {
            $fragment = $targetDom->createDocumentFragment();

            foreach ($node->childNodes as $childNode) {
                self::appendSanitizedNode($fragment, $childNode, $targetDom, $classStyles);
            }

            return $fragment->hasChildNodes() ? $fragment : null;
        }

        $styleMap = self::normalizeStyleMap($node, $classStyles, $tagName);

        if (($styleMap['vertical-align'] ?? '') === 'super') {
            $tagName = 'sup';
            unset($styleMap['vertical-align']);
        } elseif (($styleMap['vertical-align'] ?? '') === 'sub') {
            $tagName = 'sub';
            unset($styleMap['vertical-align']);
        }

        $wrappers = self::extractSemanticWrappers($styleMap);
        $element = $targetDom->createElement($tagName);

        if ($tagName === 'a') {
            $href = self::sanitizeHref((string)$node->getAttribute('href'));

            if ($href === '') {
                $fragment = $targetDom->createDocumentFragment();

                foreach ($node->childNodes as $childNode) {
                    self::appendSanitizedNode($fragment, $childNode, $targetDom, $classStyles);
                }

                return $fragment->hasChildNodes() ? $fragment : null;
            }

            $element->setAttribute('href', $href);

            if ($node->getAttribute('target') === '_blank') {
                $element->setAttribute('target', '_blank');
            }

            $rel = self::sanitizeRel((string)$node->getAttribute('rel'));
            if ($rel !== '') {
                $element->setAttribute('rel', $rel);
            }
        }

        if ($tagName === 'img') {
            $src = self::sanitizeImageSource((string)$node->getAttribute('src'));

            if ($src === '') {
                return null;
            }

            $element->setAttribute('src', $src);

            foreach (['alt', 'title'] as $attribute) {
                $value = trim((string)$node->getAttribute($attribute));

                if ($value !== '') {
                    $element->setAttribute($attribute, $value);
                }
            }
        }

        if (in_array($tagName, ['td', 'th'], true)) {
            foreach (['colspan', 'rowspan'] as $attribute) {
                $value = (int)$node->getAttribute($attribute);

                if ($value > 1) {
                    $element->setAttribute($attribute, (string)$value);
                }
            }
        }

        $style = self::buildStyleString($styleMap);
        if ($tagName !== 'a' && $style !== '') {
            $element->setAttribute('style', $style);
        }

        if (!in_array($tagName, ['br', 'hr', 'img'], true)) {
            foreach ($node->childNodes as $childNode) {
                self::appendSanitizedNode($element, $childNode, $targetDom, $classStyles);
            }
        }

        $contentAnalysis = self::analyzeNodeContent($element);

        if (in_array($tagName, ['a', 'span'], true) && !self::hasMeaningfulContent($contentAnalysis)) {
            return null;
        }

        if (self::shouldUnwrapInlineWrapper($tagName, $element, $contentAnalysis)) {
            return self::unwrapElement($element, $targetDom, $wrappers, $contentAnalysis);
        }

        if (self::shouldUnwrapHeadingStrong($tagName, $contentAnalysis)) {
            self::unwrapHeadingStrongChildren($element);
            $contentAnalysis = self::analyzeNodeContent($element);
        }

        if (!in_array($tagName, ['br', 'hr', 'img'], true) && !self::hasMeaningfulContent($contentAnalysis)) {
            return in_array($tagName, ['td', 'th'], true) ? $element : null;
        }

        $wrappers = self::normalizeWrappersForAnalysis($contentAnalysis, $wrappers);

        return self::wrapNode($element, $wrappers, $targetDom);
    }

    private static function sanitizeHref(string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '') {
            return '';
        }

        $href = self::unwrapGoogleRedirectUrl($href);

        if (preg_match('~^(https?:|mailto:|tel:|/|#)~i', $href)) {
            return $href;
        }

        return '';
    }

    private static function unwrapGoogleRedirectUrl(string $href): string
    {
        $parts = parse_url($href);

        if (!is_array($parts)) {
            return $href;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        if (!in_array($host, ['www.google.com', 'google.com'], true) || $path !== '/url') {
            return $href;
        }

        parse_str((string)($parts['query'] ?? ''), $query);
        $target = trim((string)($query['q'] ?? $query['url'] ?? ''));

        return $target !== '' ? html_entity_decode($target, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $href;
    }

    private static function sanitizeRel(string $rel): string
    {
        $allowed = ['nofollow', 'noopener', 'noreferrer', 'ugc', 'sponsored'];
        $tokens = preg_split('/\s+/', trim(strtolower($rel))) ?: [];
        $tokens = array_values(array_unique(array_intersect($allowed, $tokens)));

        return implode(' ', $tokens);
    }

    private static function sanitizeImageSource(string $src): string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (preg_match('~^https?://~i', $src) || preg_match('~^data:image/[a-z0-9.+-]+;base64,~i', $src)) {
            return $src;
        }

        return '';
    }

    /**
     * @param array<string, array<string, string>> $classStyles
     * @return array<string, string>
     */
    private static function normalizeStyleMap(DOMElement $node, array $classStyles, string $tagName): array
    {
        $styleMap = self::resolveStyleMap($node, $classStyles);

        if ($tagName === 'a' || ($tagName === 'span' && self::analyzeNodeContent($node)['onlyLinks'])) {
            unset($styleMap['color']);
        }

        self::removeDefaultInlineStyles($styleMap);

        return $styleMap;
    }

    /**
     * @param string[] $wrappers
     */
    private static function unwrapElement(
        DOMElement $element,
        DOMDocument $targetDom,
        array $wrappers = [],
        ?array $contentAnalysis = null
    ): ?DOMNode
    {
        $fragment = $targetDom->createDocumentFragment();

        while ($element->firstChild !== null) {
            $fragment->appendChild($element->firstChild);
        }

        if (!$fragment->hasChildNodes()) {
            return null;
        }

        $contentAnalysis ??= self::analyzeNodeContent($fragment);
        $wrappers = self::normalizeWrappersForAnalysis($contentAnalysis, $wrappers);

        return $wrappers ? self::wrapNode($fragment, $wrappers, $targetDom) : $fragment;
    }

    /**
     * @param array{hasMeaningfulText:bool,hasImage:bool,hasElement:bool,onlyLinks:bool,onlyStrong:bool} $contentAnalysis
     */
    private static function shouldUnwrapInlineWrapper(string $tagName, DOMElement $element, array $contentAnalysis): bool
    {
        if ($tagName === 'span') {
            return !$element->hasAttributes();
        }

        if ($tagName === 'u') {
            return $contentAnalysis['onlyLinks'];
        }

        return false;
    }

    /**
     * @param array{hasMeaningfulText:bool,hasImage:bool,hasElement:bool,onlyLinks:bool,onlyStrong:bool} $contentAnalysis
     */
    private static function shouldUnwrapHeadingStrong(string $tagName, array $contentAnalysis): bool
    {
        if (!preg_match('/^h[1-6]$/', $tagName)) {
            return false;
        }

        return $contentAnalysis['onlyStrong'];
    }

    private static function unwrapHeadingStrongChildren(DOMElement $element): void
    {
        $childNodes = [];

        foreach ($element->childNodes as $childNode) {
            $childNodes[] = $childNode;
        }

        foreach ($childNodes as $childNode) {
            if (!$childNode instanceof DOMElement || strtolower($childNode->tagName) !== 'strong') {
                continue;
            }

            while ($childNode->firstChild !== null) {
                $element->insertBefore($childNode->firstChild, $childNode);
            }

            $element->removeChild($childNode);
        }
    }

    /**
     * @param string[] $wrappers
     * @return string[]
     */
    private static function normalizeWrappersForAnalysis(array $contentAnalysis, array $wrappers): array
    {
        if (!$wrappers) {
            return $wrappers;
        }

        if (in_array('u', $wrappers, true) && $contentAnalysis['onlyLinks']) {
            $wrappers = array_values(array_filter($wrappers, static fn (string $wrapper): bool => $wrapper !== 'u'));
        }

        return $wrappers;
    }

    /**
     * @return array{hasMeaningfulText:bool,hasImage:bool,hasElement:bool,onlyLinks:bool,onlyStrong:bool}
     */
    private static function analyzeNodeContent(DOMNode $node): array
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return [
                'hasMeaningfulText' => self::hasMeaningfulText((string)$node->textContent),
                'hasImage' => false,
                'hasElement' => false,
                'onlyLinks' => false,
                'onlyStrong' => false,
            ];
        }

        if ($node instanceof DOMElement) {
            $tagName = strtolower($node->tagName);

            if ($tagName === 'a') {
                $childAnalysis = self::analyzeChildNodesContent($node);

                return [
                    'hasMeaningfulText' => $childAnalysis['hasMeaningfulText'],
                    'hasImage' => $childAnalysis['hasImage'],
                    'hasElement' => true,
                    'onlyLinks' => true,
                    'onlyStrong' => false,
                ];
            }

            if ($tagName === 'strong') {
                $childAnalysis = self::analyzeChildNodesContent($node);

                return [
                    'hasMeaningfulText' => $childAnalysis['hasMeaningfulText'],
                    'hasImage' => $childAnalysis['hasImage'],
                    'hasElement' => true,
                    'onlyLinks' => false,
                    'onlyStrong' => true,
                ];
            }

            if ($tagName === 'img') {
                return [
                    'hasMeaningfulText' => false,
                    'hasImage' => true,
                    'hasElement' => true,
                    'onlyLinks' => false,
                    'onlyStrong' => false,
                ];
            }

            $childAnalysis = self::analyzeChildNodesContent($node);

            return [
                'hasMeaningfulText' => $childAnalysis['hasMeaningfulText'],
                'hasImage' => $childAnalysis['hasImage'],
                'hasElement' => true,
                'onlyLinks' => in_array($tagName, ['u', 'span'], true) && $childAnalysis['onlyLinks'],
                'onlyStrong' => preg_match('/^h[1-6]$/', $tagName) === 1 && $childAnalysis['onlyStrong'],
            ];
        }

        return self::analyzeChildNodesContent($node);
    }

    /**
     * @return array{hasMeaningfulText:bool,hasImage:bool,hasElement:bool,onlyLinks:bool,onlyStrong:bool}
     */
    private static function analyzeChildNodesContent(DOMNode $node): array
    {
        $hasMeaningfulText = false;
        $hasImage = false;
        $hasElement = false;
        $onlyLinks = true;
        $onlyStrong = true;

        foreach ($node->childNodes as $childNode) {
            if ($childNode->nodeType === XML_TEXT_NODE) {
                if (self::hasMeaningfulText((string)$childNode->textContent)) {
                    $hasMeaningfulText = true;
                    $onlyLinks = false;
                    $onlyStrong = false;
                }

                continue;
            }

            $childAnalysis = self::analyzeNodeContent($childNode);

            $hasMeaningfulText = $hasMeaningfulText || $childAnalysis['hasMeaningfulText'];
            $hasImage = $hasImage || $childAnalysis['hasImage'];
            $hasElement = $hasElement || $childAnalysis['hasElement'];
            $onlyLinks = $onlyLinks && $childAnalysis['onlyLinks'];
            $onlyStrong = $onlyStrong && $childAnalysis['onlyStrong'];
        }

        return [
            'hasMeaningfulText' => $hasMeaningfulText,
            'hasImage' => $hasImage,
            'hasElement' => $hasElement,
            'onlyLinks' => $hasElement && $onlyLinks,
            'onlyStrong' => $hasElement && $onlyStrong,
        ];
    }

    /**
     * @param array{hasMeaningfulText:bool,hasImage:bool,hasElement:bool,onlyLinks:bool,onlyStrong:bool} $contentAnalysis
     */
    private static function hasMeaningfulContent(array $contentAnalysis): bool
    {
        return $contentAnalysis['hasMeaningfulText'] || $contentAnalysis['hasImage'];
    }

    private static function hasMeaningfulText(string $text): bool
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\p{Z}\p{C}\x{00A0}\x{200B}\x{FEFF}]+/u', '', $text);

        return is_string($text) && $text !== '';
    }

    private static function resolveStyleMap(DOMElement $node, array $classStyles): array
    {
        $styleMap = [];
        $classes = preg_split('/\s+/', trim((string)$node->getAttribute('class'))) ?: [];

        foreach ($classes as $className) {
            if ($className !== '' && isset($classStyles[$className])) {
                $styleMap = array_merge($styleMap, $classStyles[$className]);
            }
        }

        $styleMap = array_merge(
            $styleMap,
            self::parseStyleDeclarations((string)$node->getAttribute('style'), self::CLASS_STYLE_PROPERTIES)
        );

        return $styleMap;
    }

    /**
     * @param string[] $allowedProperties
     * @return array<string, string>
     */
    private static function parseStyleDeclarations(string $style, array $allowedProperties): array
    {
        $styleMap = [];

        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $value = trim($value);

            if (!in_array($property, $allowedProperties, true) || !self::isSafeStyleValue($value)) {
                continue;
            }

            $styleMap[$property] = $value;
        }

        return $styleMap;
    }

    private static function isSafeStyleValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return !preg_match('/expression\s*\(|url\s*\(|javascript:/i', $value);
    }

    /**
     * @param array<string, string> $styleMap
     */
    private static function buildStyleString(array $styleMap): string
    {
        if (!$styleMap) {
            return '';
        }

        if (($styleMap['text-align'] ?? '') === 'left') {
            unset($styleMap['text-align']);
        }

        $parts = [];

        foreach ($styleMap as $property => $value) {
            $parts[] = $property . ': ' . $value;
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string, string> $styleMap
     */
    private static function removeDefaultInlineStyles(array &$styleMap): void
    {
        $defaultValues = [
            'color' => ['#000', '#000000', 'rgb(0, 0, 0)', 'rgb(0,0,0)', 'black'],
            'font-weight' => ['400', 'normal'],
            'font-style' => ['normal'],
            'text-decoration' => ['none'],
            'vertical-align' => ['baseline'],
        ];

        foreach ($defaultValues as $property => $values) {
            if (!isset($styleMap[$property])) {
                continue;
            }

            $normalizedValue = strtolower(trim($styleMap[$property]));

            if (in_array($normalizedValue, $values, true)) {
                unset($styleMap[$property]);
            }
        }
    }

    private static function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $childNode) {
            $html .= $element->ownerDocument->saveHTML($childNode);
        }

        return $html;
    }

    /**
     * @param array<string, string> $styleMap
     * @return string[]
     */
    private static function extractSemanticWrappers(array &$styleMap): array
    {
        $wrappers = [];

        if (self::isBoldStyle($styleMap['font-weight'] ?? null)) {
            $wrappers[] = 'strong';
            unset($styleMap['font-weight']);
        }

        if (strcasecmp($styleMap['font-style'] ?? '', 'italic') === 0) {
            $wrappers[] = 'em';
            unset($styleMap['font-style']);
        }

        if (isset($styleMap['text-decoration'])) {
            $decorations = preg_split('/\s+/', strtolower($styleMap['text-decoration'])) ?: [];
            $remainingDecorations = [];

            foreach ($decorations as $decoration) {
                if ($decoration === 'underline') {
                    $wrappers[] = 'u';
                    continue;
                }

                if ($decoration === 'line-through') {
                    $wrappers[] = 's';
                    continue;
                }

                if ($decoration !== '') {
                    $remainingDecorations[] = $decoration;
                }
            }

            if ($remainingDecorations) {
                $styleMap['text-decoration'] = implode(' ', array_values(array_unique($remainingDecorations)));
            } else {
                unset($styleMap['text-decoration']);
            }
        }

        return array_values(array_unique($wrappers));
    }

    private static function isBoldStyle(?string $fontWeight): bool
    {
        if ($fontWeight === null) {
            return false;
        }

        $fontWeight = strtolower(trim($fontWeight));

        if ($fontWeight === 'bold' || $fontWeight === 'bolder') {
            return true;
        }

        return ctype_digit($fontWeight) && (int)$fontWeight >= 600;
    }

    /**
     * @param string[] $wrappers
     */
    private static function wrapNode(DOMNode $node, array $wrappers, DOMDocument $dom): DOMNode
    {
        if (!$wrappers) {
            return $node;
        }

        $wrappedNode = $node;

        foreach ($wrappers as $tagName) {
            $wrapper = $dom->createElement($tagName);
            $wrapper->appendChild($wrappedNode);
            $wrappedNode = $wrapper;
        }

        return $wrappedNode;
    }

}
