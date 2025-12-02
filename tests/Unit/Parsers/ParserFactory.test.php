<?php

declare(strict_types=1);

use App\Enums\CsvDelimiter;
use App\Mapping\FieldMapper;
use App\Models\Product;
use App\Parsers\CsvParser;
use App\Parsers\JsonParser;
use App\Parsers\ParserFactory;
use App\Parsers\XmlParser;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/parser_factory_tests_' . uniqid();
    mkdir($this->tempDir);
    $this->factory = new ParserFactory();
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

test('creates CsvParser for .csv files', function () {
    $filePath = $this->tempDir . '/test.csv';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(CsvParser::class);
});

test('creates CsvParser with TAB delimiter for .tsv files', function () {
    $filePath = $this->tempDir . '/test.tsv';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(CsvParser::class);
});

test('creates JsonParser for .json files', function () {
    $filePath = $this->tempDir . '/test.json';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(JsonParser::class);
});

test('creates JsonParser for .ndjson files', function () {
    $filePath = $this->tempDir . '/test.ndjson';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(JsonParser::class);
});

test('creates XmlParser for .xml files', function () {
    $filePath = $this->tempDir . '/test.xml';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(XmlParser::class);
});

test('handles uppercase file extensions', function () {
    $filePath = $this->tempDir . '/test.CSV';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(CsvParser::class);
});

test('handles mixed case file extensions', function () {
    $filePath = $this->tempDir . '/test.JsOn';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(JsonParser::class);
});

test('throws exception for non-existent file', function () {
    $filePath = $this->tempDir . '/non_existent.csv';

    expect(fn () => $this->factory->createFromFile($filePath))
        ->toThrow(RuntimeException::class, "File not found: {$filePath}");
});

test('throws exception for unsupported file format', function () {
    $filePath = $this->tempDir . '/test.txt';
    touch($filePath);

    expect(fn () => $this->factory->createFromFile($filePath))
        ->toThrow(RuntimeException::class, 'Unsupported file format: txt');
});

test('throws exception for file without extension', function () {
    $filePath = $this->tempDir . '/testfile';
    touch($filePath);

    expect(fn () => $this->factory->createFromFile($filePath))
        ->toThrow(RuntimeException::class, 'Unsupported file format: ');
});

test('throws exception for unknown file extension', function () {
    $filePath = $this->tempDir . '/test.pdf';
    touch($filePath);

    expect(fn () => $this->factory->createFromFile($filePath))
        ->toThrow(RuntimeException::class, 'Unsupported file format: pdf');
});

test('accepts custom FieldMapper', function () {
    $customMapper = new FieldMapper();
    $factory = new ParserFactory($customMapper);

    $filePath = $this->tempDir . '/test.csv';
    touch($filePath);

    $parser = $factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(CsvParser::class);
});

test('uses default FieldMapper when not provided', function () {
    $factory = new ParserFactory();

    $filePath = $this->tempDir . '/test.json';
    touch($filePath);

    $parser = $factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(JsonParser::class);
});

test('creates parser that can parse CSV data', function () {
    $filePath = seedCsvFile($this->tempDir, 2, CsvDelimiter::COMMA);

    $parser = $this->factory->createFromFile($filePath);
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class);
});

test('creates parser that can parse JSON data', function () {
    $filePath = seedJsonFile($this->tempDir, 2);

    $parser = $this->factory->createFromFile($filePath);
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class);
});

test('creates parser that can parse XML data', function () {
    $filePath = seedXmlFile($this->tempDir, 2);

    $parser = $this->factory->createFromFile($filePath);
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class);
});

test('creates parser that can parse NDJSON data', function () {
    $filePath = seedNdjsonFile($this->tempDir, 2);

    $parser = $this->factory->createFromFile($filePath);
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class);
});

test('creates parser that can parse TSV data', function () {
    $filePath = seedCsvFile($this->tempDir, 2, CsvDelimiter::TAB);

    $parser = $this->factory->createFromFile($filePath);
    $products = iterator_to_array($parser->parse($filePath));

    expect($products)->toHaveCount(2)
        ->and($products[0])->toBeInstanceOf(Product::class);
});

test('handles files with dots in filename', function () {
    $filePath = $this->tempDir . '/my.test.file.csv';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(CsvParser::class);
});

test('handles file paths with spaces', function () {
    $dirWithSpaces = $this->tempDir . '/test dir';
    mkdir($dirWithSpaces);
    $filePath = $dirWithSpaces . '/test file.json';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(JsonParser::class);

    // Cleanup
    unlink($filePath);
    rmdir($dirWithSpaces);
});

test('handles absolute paths', function () {
    $filePath = $this->tempDir . '/test.xml';
    touch($filePath);

    $parser = $this->factory->createFromFile($filePath);

    expect($parser)->toBeInstanceOf(XmlParser::class);
});
