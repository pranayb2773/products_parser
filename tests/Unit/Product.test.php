<?php

declare(strict_types=1);

use App\Exceptions\RequiredFieldException;
use App\Models\Product;

test('product can be created with all fields', function () {
    $product = Product::create(
        make: 'Apple',
        model: 'iPhone 13',
        colour: 'Blue',
        capacity: '128GB',
        network: 'Unlocked',
        grade: 'Grade A',
        condition: 'Working'
    );

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->make)->toBe('Apple')
        ->and($product->model)->toBe('iPhone 13')
        ->and($product->colour)->toBe('Blue')
        ->and($product->capacity)->toBe('128GB')
        ->and($product->network)->toBe('Unlocked')
        ->and($product->grade)->toBe('Grade A')
        ->and($product->condition)->toBe('Working');
});

test('product can be created with only required fields', function () {
    $product = Product::create(
        make: 'Samsung',
        model: 'Galaxy 24 Ultra'
    );

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->make)->toBe('Samsung')
        ->and($product->model)->toBe('Galaxy 24 Ultra')
        ->and($product->colour)->toBeNull()
        ->and($product->capacity)->toBeNull()
        ->and($product->network)->toBeNull()
        ->and($product->grade)->toBeNull()
        ->and($product->condition)->toBeNull();
});

test('product throws exception when make is missing', function () {
    expect(fn() => Product::fromArray(['model' => 'iPhone 12']))
        ->toThrow(RequiredFieldException::class, "Required field 'make' is missing or empty");
});

test('product throws exception when model is missing', function () {
    expect(fn() => Product::fromArray(['make' => 'Apple']))
        ->toThrow(RequiredFieldException::class, "Required field 'model' is missing or empty");
});

test('product throws exception when make is empty', function () {
    expect(fn() => Product::fromArray(['make' => '', 'model' => 'iPhone 12']))
        ->toThrow(RequiredFieldException::class);
});

test('product to array returns correct structure', function () {
    $product = Product::fromArray([
        'make' => 'Apple',
        'model' => 'iPhone 12',
        'colour' => 'Blue',
    ]);

    $array = $product->toArray();

    expect($array)->toBeArray()
        ->and($array['make'])->toBe('Apple')
        ->and($array['model'])->toBe('iPhone 12')
        ->and($array['colour'])->toBe('Blue')
        ->and($array['capacity'])->toBeNull();
});

test('product unique key generates consistent key', function () {
    $product1 = Product::fromArray([
        'make' => 'Apple',
        'model' => 'iPhone 12',
        'colour' => 'Blue',
        'capacity' => '128GB',
    ]);

    $product2 = Product::fromArray([
        'make' => 'Apple',
        'model' => 'iPhone 12',
        'colour' => 'Blue',
        'capacity' => '128GB',
    ]);

    expect($product1->getUniqueKey())->toBe($product2->getUniqueKey());
});

test('product unique key different for different products', function () {
    $product1 = Product::fromArray([
        'make' => 'Apple',
        'model' => 'iPhone 12',
        'colour' => 'Blue',
    ]);

    $product2 = Product::fromArray([
        'make' => 'Apple',
        'model' => 'iPhone 12',
        'colour' => 'Red',
    ]);

    expect($product1->getUniqueKey())->not->toBe($product2->getUniqueKey());
});
