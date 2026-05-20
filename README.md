# Google Doc Reader

Reads a public Google Docs document as sanitized HTML. Images are not downloaded or saved; their original `src` values remain in the returned HTML.

## Install

For local development, add a path repository to your project `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "components/google-doc-reader"
    }
  ],
  "require": {
    "heorhiev/google-doc-reader": "dev-master"
  }
}
```

Then run:

```bash
composer update heorhiev/google-doc-reader
```

## Usage

```php
use Heorhiev\GoogleDocReader\GoogleDocReader;

$result = GoogleDocReader::readFromUrl($googleDocUrl);

$title = $result->title;
$html = $result->html;
```

## Tests

```bash
composer test
```
