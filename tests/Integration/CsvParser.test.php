<?php

declare(strict_types=1);

use App\Mapping\FieldMapper;
use App\Models\Product;
use App\Parsers\CsvParser;

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

test('parse comma separated csv', function () {
    $csvContent = <<<CSV
brand_name,model_name,colour_name,capacity,network_name,grade_name,condition_name
Apple,iPhone 12,Blue,128GB,Unlocked,Grade A,Working
Samsung,Galaxy S21,Black,256GB,Unlocked,Grade B,Working
CSV;

    $filePath = $this->tempDir . '/test.csv';
    file_put_contents($filePath, $csvContent);

    $parser = new CsvParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class)
        ->and($products[0]->make)->toBe('Apple')
        ->and($products[0]->model)->toBe('iPhone 12')
        ->and($products[0]->colour)->toBe('Blue');
});

test('parse tab separated csv', function () {
    $tsvContent = "brand_name\tmodel_name\tcolour_name\n";
    $tsvContent .= "Apple\tiPhone 12\tBlue\n";
    $tsvContent .= "Samsung\tGalaxy S21\tBlack\n";

    $filePath = $this->tempDir . '/test.tsv';
    file_put_contents($filePath, $tsvContent);

    $parser = new CsvParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0]->make)->toBe('Apple')
        ->and($products[1]->make)->toBe('Samsung');
});

test('parse handles quoted values', function () {
    $csvContent = <<<CSV
"brand_name","model_name","colour_name"
"Apple","iPhone 12","Blue"
"Samsung","Galaxy S21","Black"
CSV;

    $filePath = $this->tempDir . '/test.csv';
    file_put_contents($filePath, $csvContent);

    $parser = new CsvParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0]->make)->toBe('Apple');
});

test('parse throws exception for missing required field', function () {
    $csvContent = <<<CSV
brand_name,colour_name
Apple,Blue
CSV;

    $filePath = $this->tempDir . '/test.csv';
    file_put_contents($filePath, $csvContent);

    $parser = new CsvParser(new FieldMapper());

    expect(fn () => iterator_to_array($parser->parse($filePath)))
        ->toThrow(RuntimeException::class, "Required field 'model' is missing or empty");
});

test('parse handles empty lines', function () {
    $csvContent = "brand_name,model_name\nApple,iPhone 12\n\nSamsung,Galaxy S21";

    $filePath = $this->tempDir . '/test.csv';
    file_put_contents($filePath, $csvContent);

    $parser = new CsvParser(new FieldMapper());
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2);
});

test('parse uses generator for memory efficiency', function () {
    $csvContent = "brand_name,model_name\n";
    for ($i = 1; $i <= 100; $i++) {
        $csvContent .= "Brand{$i},Model{$i}\n";
    }

    $filePath = $this->tempDir . '/test.csv';
    file_put_contents($filePath, $csvContent);

    $parser = new CsvParser(new FieldMapper());
    $generator = $parser->parse($filePath);

    expect($generator)->toBeInstanceOf(Generator::class);

    $count = 0;
    foreach ($generator as $product) {
        $count++;
        if ($count === 10) {
            break;
        }
    }

    expect($count)->toBe(10);
});
