<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\UniqueCounter;

test('add product increases count', function () {
    $counter = new UniqueCounter();
    $product = Product::fromArray(['make' => 'Apple', 'model' => 'iPhone 12']);

    $counter->addProduct($product);

    expect($counter->getCount())->toBe(1);
});

test('add same product twice counts as one', function () {
    $counter = new UniqueCounter();
    $product1 = Product::fromArray(['make' => 'Apple', 'model' => 'iPhone 12', 'colour' => 'Blue']);
    $product2 = Product::fromArray(['make' => 'Apple', 'model' => 'iPhone 12', 'colour' => 'Blue']);

    $counter->addProduct($product1);
    $counter->addProduct($product2);

    expect($counter->getCount())->toBe(1);

    $combinations = $counter->getCombinations();
    expect($combinations[array_key_first($combinations)]['count'])->toBe(2);
});

test('add different products counts as two', function () {
    $counter = new UniqueCounter();
    $product1 = Product::fromArray(['make' => 'Apple', 'model' => 'iPhone 12', 'colour' => 'Blue']);
    $product2 = Product::fromArray(['make' => 'Apple', 'model' => 'iPhone 12', 'colour' => 'Red']);

    $counter->addProduct($product1);
    $counter->addProduct($product2);

    expect($counter->getCount())->toBe(2);
});

test('write to csv creates file', function () {
    $counter = new UniqueCounter();
    $product = Product::fromArray([
        'make' => 'Apple',
        'model' => 'iPhone 12',
        'colour' => 'Blue',
        'capacity' => '128GB',
        'network' => 'Unlocked',
        'grade' => 'Grade A',
        'condition' => 'Working',
    ]);

    $counter->addProduct($product);
    $counter->addProduct($product);

    $tempFile = sys_get_temp_dir() . '/test_output_' . uniqid() . '.csv';

    $counter->writeToCsv($tempFile);

    expect($tempFile)->toBeFile();

    $contents = file_get_contents($tempFile);
    expect($contents)
        ->toContain('make,model,colour,capacity,network,grade,condition,count')
        ->toContain('Apple')
        ->toContain('iPhone 12')
        ->toContain('2');

    unlink($tempFile);
});

test('write to json creates file', function () {
    $counter = new UniqueCounter();
    $product = Product::fromArray([
        'make' => 'Samsung',
        'model' => 'Galaxy S21',
        'colour' => 'Black',
        'capacity' => '256GB',
        'network' => 'Unlocked',
        'grade' => 'Grade B',
        'condition' => 'Working',
    ]);

    $counter->addProduct($product);
    $counter->addProduct($product);

    $tempFile = sys_get_temp_dir() . '/test_output_' . uniqid() . '.json';

    $counter->writeToJson($tempFile);

    expect($tempFile)->toBeFile();

    $contents = file_get_contents($tempFile);
    $data = json_decode($contents, true);

    expect($data)->toBeArray()
        ->toHaveCount(1)
        ->and($data[0]['make'])->toBe('Samsung')
        ->and($data[0]['model'])->toBe('Galaxy S21')
        ->and($data[0]['colour'])->toBe('Black')
        ->and($data[0]['count'])->toBe(2);

    unlink($tempFile);
});

test('write to xml creates file', function () {
    $counter = new UniqueCounter();
    $product = Product::fromArray([
        'make' => 'Google',
        'model' => 'Pixel 6',
        'colour' => 'Green',
        'capacity' => '128GB',
        'network' => 'Unlocked',
        'grade' => 'Grade A',
        'condition' => 'Working',
    ]);

    $counter->addProduct($product);
    $counter->addProduct($product);
    $counter->addProduct($product);

    $tempFile = sys_get_temp_dir() . '/test_output_' . uniqid() . '.xml';

    $counter->writeToXml($tempFile);

    expect($tempFile)->toBeFile();

    $contents = file_get_contents($tempFile);
    $xml = simplexml_load_string($contents);

    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('products')
        ->and($xml->product)->toHaveCount(1)
        ->and((string) $xml->product[0]->make)->toBe('Google')
        ->and((string) $xml->product[0]->model)->toBe('Pixel 6')
        ->and((string) $xml->product[0]->colour)->toBe('Green')
        ->and((string) $xml->product[0]->count)->toBe('3');

    unlink($tempFile);
});

test('write to file auto detects format', function () {
    $counter = new UniqueCounter();
    $product = Product::fromArray(['make' => 'OnePlus', 'model' => '9 Pro']);

    $counter->addProduct($product);

    // Test CSV auto-detection
    $csvFile = sys_get_temp_dir() . '/test_auto_' . uniqid() . '.csv';
    $counter->writeToFile($csvFile);
    expect($csvFile)->toBeFile();
    $csvContents = file_get_contents($csvFile);
    expect($csvContents)->toContain('make,model');
    unlink($csvFile);

    // Test JSON auto-detection
    $jsonFile = sys_get_temp_dir() . '/test_auto_' . uniqid() . '.json';
    $counter->writeToFile($jsonFile);
    expect($jsonFile)->toBeFile();
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    expect($jsonData)->toBeArray();
    unlink($jsonFile);

    // Test XML auto-detection
    $xmlFile = sys_get_temp_dir() . '/test_auto_' . uniqid() . '.xml';
    $counter->writeToFile($xmlFile);
    expect($xmlFile)->toBeFile();
    $xmlData = simplexml_load_file($xmlFile);
    expect($xmlData)->not->toBeFalse();
    unlink($xmlFile);
});
