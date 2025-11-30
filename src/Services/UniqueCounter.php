<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CsvDelimiter;
use App\Enums\CsvFormat;
use App\Enums\OutputFormat;
use App\Models\Product;
use DOMDocument;
use JsonException;
use RuntimeException;
use SimpleXMLElement;

final class UniqueCounter
{
    private array $combinations = [];
    private array $tempFiles = [];
    private ?array $cachedAggregated = null;

    public function __construct(
        private readonly int $chunkSize = 0 // 0 disables chunking; set >0 to chunk by unique count
    ) {
    }

    public function __destruct()
    {
        $this->cleanupTempFiles();
    }

    /**
     * @throws JsonException
     */
    public function addProduct(Product $product): void
    {
        $key = $product->getUniqueKey();

        if (!isset($this->combinations[$key])) {
            $this->combinations[$key] = [
                'product' => $product,
                'count' => 0,
            ];
        }

        $this->combinations[$key]['count']++;
        $this->cachedAggregated = null;

        if ($this->chunkSize > 0 && count($this->combinations) >= $this->chunkSize) {
            $this->flushChunk();
        }
    }

    /**
     * @throws JsonException
     */
    public function writeToFile(string $filePath): void
    {
        $this->ensureAggregated();
        $format = OutputFormat::fromFilePath($filePath);

        match ($format) {
            OutputFormat::CSV => $this->writeToCsv($filePath),
            OutputFormat::JSON => $this->writeToJson($filePath),
            OutputFormat::XML => $this->writeToXml($filePath),
        };
    }

    public function writeToCsv(string $filePath): void
    {
        $this->ensureAggregated();
        $handle = fopen($filePath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Failed to open file for writing: {$filePath}");
        }

        try {
            $this->writeCsvHeader($handle);
            $this->writeCsvRows($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws JsonException
     */
    public function writeToJson(string $filePath): void
    {
        $this->ensureAggregated();
        $data = [];

        foreach ($this->combinations as $combinationData) {
            $product = $combinationData['product'];
            $count = $combinationData['count'];

            $data[] = [
                'make' => $product->make,
                'model' => $product->model,
                'colour' => $product->colour,
                'capacity' => $product->capacity,
                'network' => $product->network,
                'grade' => $product->grade,
                'condition' => $product->condition,
                'count' => $count,
            ];
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write JSON file: {$filePath}");
        }
    }

    public function writeToXml(string $filePath): void
    {
        $this->ensureAggregated();
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><products></products>');

        foreach ($this->combinations as $combinationData) {
            $product = $combinationData['product'];
            $count = $combinationData['count'];

            $productElement = $xml->addChild('product');
            $productElement->addChild('make', htmlspecialchars($product->make ?? '', ENT_XML1));
            $productElement->addChild('model', htmlspecialchars($product->model ?? '', ENT_XML1));
            $productElement->addChild('colour', htmlspecialchars($product->colour ?? '', ENT_XML1));
            $productElement->addChild('capacity', htmlspecialchars($product->capacity ?? '', ENT_XML1));
            $productElement->addChild('network', htmlspecialchars($product->network ?? '', ENT_XML1));
            $productElement->addChild('grade', htmlspecialchars($product->grade ?? '', ENT_XML1));
            $productElement->addChild('condition', htmlspecialchars($product->condition ?? '', ENT_XML1));
            $productElement->addChild('count', (string) $count);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        if ($dom->save($filePath) === false) {
            throw new RuntimeException("Failed to write XML file: {$filePath}");
        }
    }

    public function getCount(): int
    {
        $this->ensureAggregated();
        return count($this->combinations);
    }

    public function getCombinations(): array
    {
        $this->ensureAggregated();
        return $this->combinations;
    }

    private function writeCsvHeader($handle): void
    {
        fputcsv(
            $handle,
            ['make', 'model', 'colour', 'capacity', 'network', 'grade', 'condition', 'count'],
            CsvDelimiter::COMMA->value,
            CsvFormat::ENCLOSURE->value,
            CsvFormat::ESCAPE->value
        );
    }

    private function writeCsvRows($handle): void
    {
        foreach ($this->combinations as $data) {
            $product = $data['product'];
            $count = $data['count'];

            fputcsv(
                $handle,
                [
                    $product->make,
                    $product->model,
                    $product->colour,
                    $product->capacity,
                    $product->network,
                    $product->grade,
                    $product->condition,
                    $count,
                ],
                CsvDelimiter::COMMA->value,
                CsvFormat::ENCLOSURE->value,
                CsvFormat::ESCAPE->value
            );
        }
    }

    private function ensureAggregated(): void
    {
        if ($this->cachedAggregated !== null) {
            $this->combinations = $this->cachedAggregated;
            return;
        }

        if (empty($this->tempFiles)) {
            $this->cachedAggregated = $this->combinations;
            return;
        }

        $merged = $this->combinations;

        foreach ($this->tempFiles as $file) {
            $handle = fopen($file, 'r');

            if ($handle === false) {
                throw new RuntimeException("Failed to open temp file: {$file}");
            }

            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode($line, true);

                if (!is_array($decoded)) {
                    continue;
                }

                $key = $decoded['key'] ?? null;
                $data = $decoded['data'] ?? null;
                $count = $decoded['count'] ?? 0;

                if (!is_string($key) || !is_array($data)) {
                    continue;
                }

                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'product' => Product::fromArray($data),
                        'count' => 0,
                    ];
                }

                $merged[$key]['count'] += (int) $count;
            }

            fclose($handle);
        }

        $this->combinations = $merged;
        $this->cachedAggregated = $merged;
        $this->cleanupTempFiles();
    }

    /**
     * @throws JsonException
     */
    private function flushChunk(): void
    {
        if (empty($this->combinations)) {
            return;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'unique_chunk_');

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for chunking');
        }

        $handle = fopen($tempFile, 'w');

        if ($handle === false) {
            throw new RuntimeException('Failed to open temporary file for chunking');
        }

        foreach ($this->combinations as $key => $data) {
            $payload = [
                'key' => $key,
                'data' => $data['product']->toArray(),
                'count' => $data['count'],
            ];

            fwrite($handle, json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL);
        }

        fclose($handle);

        $this->tempFiles[] = $tempFile;
        $this->combinations = [];
    }

    private function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];
    }
}
