<?php

declare(strict_types=1);

use App\Cli\ParserOutputWriter;
use App\Contracts\FileParserInterface;
use App\Enums\CsvDelimiter;
use App\Models\Product;
use App\Parsers\CsvParser;
use App\Parsers\JsonParser;
use App\Parsers\ParserFactory;
use App\Parsers\XmlParser;
use App\Services\ParallelProcessor;
use App\Services\UniqueCounter;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/parallel_processor_tests_' . uniqid();
    mkdir($this->tempDir);
    $this->output = new ParserOutputWriter();
    $this->hasPcntl = extension_loaded('pcntl');
    $this->hasXmlReader = extension_loaded('xmlreader');
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    // Cleanup temp parallel processor directory
    $tempParallelDir = sys_get_temp_dir() . '/products_parser';
    if (is_dir($tempParallelDir)) {
        $files = glob($tempParallelDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($tempParallelDir);
    }
});

test('constructor throws exception when PCNTL extension is not loaded', function () {
    if ($this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is loaded, cannot test missing extension scenario');
    }

    expect(fn () => new ParallelProcessor(2, new ParserOutputWriter()))
        ->toThrow(RuntimeException::class, 'PCNTL extension is required for parallel processing');
});

test('constructor throws exception when worker count is less than 1', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    expect(fn () => new ParallelProcessor(0, $this->output))
        ->toThrow(RuntimeException::class, 'Worker count must be at least 1');
});

test('constructor throws exception when worker count is negative', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    expect(fn () => new ParallelProcessor(-1, $this->output))
        ->toThrow(RuntimeException::class, 'Worker count must be at least 1');
});

test('constructor accepts valid worker count', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $processor = new ParallelProcessor(4, $this->output);

    expect($processor)->toBeInstanceOf(ParallelProcessor::class);
});

test('process throws exception for non-existent file', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $processor = new ParallelProcessor(2, $this->output);
    $parser = new CsvParser();
    $nonExistentFile = $this->tempDir . '/non_existent.csv';

    expect(fn () => $processor->process($parser, $nonExistentFile))
        ->toThrow(RuntimeException::class, "Input file not found: {$nonExistentFile}");
});

test('process throws exception for unsupported file format', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $processor = new ParallelProcessor(2, $this->output);
    $parser = new CsvParser();
    $unsupportedFile = $this->tempDir . '/test.txt';
    touch($unsupportedFile);

    expect(fn () => $processor->process($parser, $unsupportedFile))
        ->toThrow(RuntimeException::class, 'Parallel processing not supported for .txt files');
});

test('processes CSV file with multiple workers', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 10, CsvDelimiter::COMMA);
    $parser = new CsvParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('processes JSON file with multiple workers', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedJsonFile($this->tempDir, 10);
    $parser = new JsonParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('processes XML file with multiple workers', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedXmlFile($this->tempDir, 10);
    $parser = new XmlParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('processes NDJSON file with multiple workers', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedNdjsonFile($this->tempDir, 10);
    $parser = new JsonParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('processes file with single worker', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 5, CsvDelimiter::COMMA);
    $parser = new CsvParser();
    $processor = new ParallelProcessor(1, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('processes file with four workers', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 20, CsvDelimiter::COMMA);
    $parser = new CsvParser();
    $processor = new ParallelProcessor(4, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('merges results correctly from multiple workers', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 20, CsvDelimiter::COMMA);
    $parser = new CsvParser();
    $processor = new ParallelProcessor(3, $this->output);

    $counter = $processor->process($parser, $filePath);
    $combinations = $counter->getCombinations();

    expect($combinations)->toBeArray()
        ->and(count($combinations))->toBeGreaterThan(0);

    foreach ($combinations as $combination) {
        expect($combination)->toHaveKey('product')
            ->and($combination)->toHaveKey('count')
            ->and($combination['product'])->toBeInstanceOf(Product::class)
            ->and($combination['count'])->toBeInt()
            ->and($combination['count'])->toBeGreaterThan(0);
    }
});

test('handles empty CSV file', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $csvContent = "brand_name,model_name,colour_name\n";
    $filePath = $this->tempDir . '/empty.csv';
    file_put_contents($filePath, $csvContent);

    $parser = new CsvParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter->getCount())->toBe(0);
});

test('handles small file with more workers than data rows', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 2, CsvDelimiter::COMMA);
    $parser = new CsvParser();
    $processor = new ParallelProcessor(10, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('handles mixed case file extensions', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 5, CsvDelimiter::COMMA);
    $upperCaseFile = $this->tempDir . '/test.CSV';
    rename($filePath, $upperCaseFile);

    $parser = new CsvParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $upperCaseFile);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('processes large CSV file efficiently', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 100, CsvDelimiter::COMMA);
    $parser = new CsvParser();
    $processor = new ParallelProcessor(4, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter)->toBeInstanceOf(UniqueCounter::class)
        ->and($counter->getCount())->toBeGreaterThan(0);
});

test('counts products correctly across workers', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 20, CsvDelimiter::COMMA);

    // Process with parallel processor
    $parser = new CsvParser();
    $processor = new ParallelProcessor(3, $this->output);
    $parallelCounter = $processor->process($parser, $filePath);

    // Process sequentially for comparison
    $sequentialParser = new CsvParser();
    $sequentialCounter = new UniqueCounter();
    foreach ($sequentialParser->parse($filePath) as $product) {
        $sequentialCounter->addProduct($product);
    }

    // Both should have same unique count
    expect($parallelCounter->getCount())->toBe($sequentialCounter->getCount());
});

test('cleans up temporary files after processing', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $filePath = seedCsvFile($this->tempDir, 10, CsvDelimiter::COMMA);
    $parser = new CsvParser();
    $tempDir = sys_get_temp_dir() . '/products_parser';

    // Use a scope to ensure processor is destroyed
    {
        $processor = new ParallelProcessor(2, $this->output);
        $processor->process($parser, $filePath);
        unset($processor); // Explicitly destroy to trigger __destruct
    }

    // Give a moment for cleanup
    usleep(100000); // 100ms

    // Check that temp files are cleaned up
    if (is_dir($tempDir)) {
        $files = glob($tempDir . '/*');
        expect($files)->toBeEmpty();
    }
});

test('handles JSON array format', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $jsonContent = json_encode([
        ['brand_name' => 'Apple', 'model_name' => 'iPhone 12', 'colour_name' => 'Black'],
        ['brand_name' => 'Samsung', 'model_name' => 'Galaxy S21', 'colour_name' => 'White'],
    ]);
    $filePath = $this->tempDir . '/test.json';
    file_put_contents($filePath, $jsonContent);

    $parser = new JsonParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter->getCount())->toBe(2);
});

test('handles XML with multiple product elements', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<products>
    <product>
        <brand_name>Apple</brand_name>
        <model_name>iPhone 12</model_name>
        <colour_name>Black</colour_name>
    </product>
    <product>
        <brand_name>Samsung</brand_name>
        <model_name>Galaxy S21</model_name>
        <colour_name>White</colour_name>
    </product>
</products>
XML;
    $filePath = $this->tempDir . '/test.xml';
    file_put_contents($filePath, $xmlContent);

    $parser = new XmlParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter->getCount())->toBe(2);
});

test('processes files with special characters in path', function () {
    if (!$this->hasPcntl) {
        $this->markTestSkipped('PCNTL extension is required');
    }

    $specialDir = $this->tempDir . '/test-dir_123';
    mkdir($specialDir);

    $csvContent = "brand_name,model_name,colour_name\nApple,iPhone,Black\n";
    $filePath = $specialDir . '/test-file.csv';
    file_put_contents($filePath, $csvContent);

    $parser = new CsvParser();
    $processor = new ParallelProcessor(2, $this->output);

    $counter = $processor->process($parser, $filePath);

    expect($counter->getCount())->toBe(1);

    // Cleanup
    unlink($filePath);
    rmdir($specialDir);
});
