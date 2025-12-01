<?php

declare(strict_types=1);

use App\Mapping\FieldMapper;
use App\Models\Product;
use App\Parsers\XmlParser;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/parser_tests_' . uniqid();
    mkdir($this->tempDir);
});

afterEach(function () {
    $files = glob($this->tempDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($this->tempDir);
});

test('parse xml with products wrapper', function () {
    $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <brand_name>Apple</brand_name>
        <model_name>iPhone 12</model_name>
        <colour_name>Blue</colour_name>
    </product>
    <product>
        <brand_name>Samsung</brand_name>
        <model_name>Galaxy S21</model_name>
        <colour_name>Black</colour_name>
    </product>
</products>
XML;

    $filePath = $this->tempDir . '/test.xml';
    file_put_contents($filePath, $xmlContent);

    $parser = new XmlParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class)
        ->and($products[0]->make)->toBe('Apple')
        ->and($products[0]->model)->toBe('iPhone 12')
        ->and($products[0]->colour)->toBe('Blue');
});

test('parse xml with items wrapper', function () {
    $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<items>
    <item>
        <brand_name>Google</brand_name>
        <model_name>Pixel 6</model_name>
    </item>
</items>
XML;

    $filePath = $this->tempDir . '/test.xml';
    file_put_contents($filePath, $xmlContent);

    $parser = new XmlParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(1)
        ->and($products[0]->make)->toBe('Google')
        ->and($products[0]->model)->toBe('Pixel 6');
});

test('parse xml with attributes', function () {
    $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product brand_name="OnePlus" model_name="9 Pro">
        <colour_name>Silver</colour_name>
    </product>
</products>
XML;

    $filePath = $this->tempDir . '/test.xml';
    file_put_contents($filePath, $xmlContent);

    $parser = new XmlParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(1)
        ->and($products[0]->make)->toBe('OnePlus')
        ->and($products[0]->model)->toBe('9 Pro')
        ->and($products[0]->colour)->toBe('Silver');
});

test('parse throws exception for invalid xml', function () {
    $filePath = $this->tempDir . '/test.xml';
    file_put_contents($filePath, '<invalid xml>');

    $parser = new XmlParser(new FieldMapper());

    expect(fn () => iterator_to_array($parser->parse($filePath)))
        ->toThrow(RuntimeException::class, 'Failed to parse XML');
});

test('parse throws exception for missing required field', function () {
    $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <brand_name>Apple</brand_name>
    </product>
</products>
XML;

    $filePath = $this->tempDir . '/test.xml';
    file_put_contents($filePath, $xmlContent);

    $parser = new XmlParser(new FieldMapper());

    expect(fn () => iterator_to_array($parser->parse($filePath)))
        ->toThrow(RuntimeException::class, "Required field 'model' is missing or empty");
});
