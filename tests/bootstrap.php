<?php

use Heorhiev\GoogleDocReader\GoogleDocReader;

$autoloadPaths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

if (!class_exists(GoogleDocReader::class)) {
    require_once dirname(__DIR__) . '/src/Dto/GoogleDocReadResult.php';
    require_once dirname(__DIR__) . '/src/Support/GoogleDocHtmlFetcher.php';
    require_once dirname(__DIR__) . '/src/Support/GoogleDocHtmlSanitizer.php';
    require_once dirname(__DIR__) . '/src/GoogleDocReader.php';
}
