<?php

declare(strict_types=1);

use App\Mapping\FieldMapper;

test('map row with default mappings', function () {
    $mapper = new FieldMapper();
    $headers = ['brand_name', 'model_name', 'colour_name'];
    $row = ['Apple', 'iPhone 12', 'Blue'];

    $result = $mapper->mapRow($headers, $row);

    expect($result['make'])->toBe('Apple')
        ->and($result['model'])->toBe('iPhone 12')
        ->and($result['colour'])->toBe('Blue');
});

test('map row with direct mappings', function () {
    $mapper = new FieldMapper();
    $headers = ['make', 'model', 'capacity'];
    $row = ['Samsung', 'Galaxy S21', '256GB'];

    $result = $mapper->mapRow($headers, $row);

    expect($result['make'])->toBe('Samsung')
        ->and($result['model'])->toBe('Galaxy S21')
        ->and($result['capacity'])->toBe('256GB');
});

test('map row ignores unknown headers', function () {
    $mapper = new FieldMapper();
    $headers = ['make', 'unknown_field', 'model'];
    $row = ['Apple', 'some_value', 'iPhone 12'];

    $result = $mapper->mapRow($headers, $row);

    expect($result['make'])->toBe('Apple')
        ->and($result['model'])->toBe('iPhone 12')
        ->and($result)->not->toHaveKey('unknown_field');
});

test('map row handles case insensitive headers', function () {
    $mapper = new FieldMapper();
    $headers = ['BRAND_NAME', 'Model_Name', 'COLOUR_name'];
    $row = ['Apple', 'iPhone 12', 'Blue'];

    $result = $mapper->mapRow($headers, $row);

    expect($result['make'])->toBe('Apple')
        ->and($result['model'])->toBe('iPhone 12')
        ->and($result['colour'])->toBe('Blue');
});

test('add custom mapping', function () {
    $mapper = new FieldMapper();
    $mapper->addMapping('custom_brand', 'make');

    $headers = ['custom_brand', 'model'];
    $row = ['Apple', 'iPhone 12'];

    $result = $mapper->mapRow($headers, $row);

    expect($result['make'])->toBe('Apple')
        ->and($result['model'])->toBe('iPhone 12');
});

test('map row with custom mappings in constructor', function () {
    $customMappings = [
        'manufacturer' => 'make',
        'product_name' => 'model',
    ];

    $mapper = new FieldMapper($customMappings);
    $headers = ['manufacturer', 'product_name'];
    $row = ['Sony', 'Xperia 1'];

    $result = $mapper->mapRow($headers, $row);

    expect($result['make'])->toBe('Sony')
        ->and($result['model'])->toBe('Xperia 1');
});
