<?php

declare(strict_types=1);

use App\Mapping\FieldMapper;
use App\Models\Product;
use App\Parsers\JsonParser;

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

test('parse json direct array', function () {
    $jsonContent = json_encode([
        [
            'brand_name' => 'Google',
            'model_name' => 'Pixel 6',
        ],
    ]);

    $filePath = $this->tempDir . '/test.json';
    file_put_contents($filePath, $jsonContent);

    $parser = new JsonParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(1)
        ->and($products[0]->make)->toBe('Google');
});

test('parse json single object', function () {
    $jsonContent = json_encode([
        'brand_name' => 'OnePlus',
        'model_name' => 'Nord',
        'colour_name' => 'Gray',
    ]);

    $filePath = $this->tempDir . '/test.json';
    file_put_contents($filePath, $jsonContent);

    $parser = new JsonParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(1)
        ->and($products[0]->make)->toBe('OnePlus')
        ->and($products[0]->model)->toBe('Nord');
});

test('parse ndjson format', function () {
    $line1 = json_encode(['brand_name' => 'Apple', 'model_name' => 'iPhone 13']);
    $line2 = json_encode(['brand_name' => 'Samsung', 'model_name' => 'Galaxy S22']);
    $jsonContent = $line1 . "\n" . $line2;

    $filePath = $this->tempDir . '/test.json';
    file_put_contents($filePath, $jsonContent);

    $parser = new JsonParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0]->make)->toBe('Apple')
        ->and($products[1]->make)->toBe('Samsung');
});

test('parse throws exception for invalid json', function () {
    $filePath = $this->tempDir . '/test.json';
    file_put_contents($filePath, '{invalid json}');

    $parser = new JsonParser(new FieldMapper());

    expect(fn () => iterator_to_array($parser->parse($filePath)))
        ->toThrow(RuntimeException::class, 'Invalid JSON');
});

test('parse throws exception for missing required field', function () {
    $jsonContent = json_encode([
        ['brand_name' => 'Apple'],
    ]);

    $filePath = $this->tempDir . '/test.json';
    file_put_contents($filePath, $jsonContent);

    $parser = new JsonParser(new FieldMapper());

    expect(fn () => iterator_to_array($parser->parse($filePath)))
        ->toThrow(RuntimeException::class, "Required field 'model' is missing or empty");
});

test('parse json with products array', function () {
    $jsonContent = json_encode([
        [
            'brand_name' => 'Apple',
            'model_name' => 'iPhone 12',
            'colour_name' => 'Blue',
        ],
        [
            'brand_name' => 'Samsung',
            'model_name' => 'Galaxy S21',
            'colour_name' => 'Black',
        ],
    ]);

    $filePath = $this->tempDir . '/test.json';
    file_put_contents($filePath, $jsonContent);

    $parser = new JsonParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class)
        ->and($products[0]->make)->toBe('Apple')
        ->and($products[0]->model)->toBe('iPhone 12');
});
