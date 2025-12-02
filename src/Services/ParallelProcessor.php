<?php

declare(strict_types=1);

namespace App\Services;

use App\Cli\ParserOutputWriter;
use App\Contracts\FileParserInterface;
use App\Models\Product;
use RuntimeException;
use Throwable;
use XMLReader;

final class ParallelProcessor
{
    private array $workerPids = [];
    private array $partitionFiles = [];
    private array $resultFiles = [];
    private bool $isParentProcess = true;

    public function __construct(
        private readonly int $workerCount,
        private readonly ParserOutputWriter $output
    ) {
        if (!extension_loaded('pcntl')) {
            throw new RuntimeException('PCNTL extension is required for parallel processing');
        }
        if (!extension_loaded('xmlreader')) {
            throw new RuntimeException('XMLReader extension is required for XML processing');
        }
        if ($workerCount < 1) {
            throw new RuntimeException('Worker count must be at least 1');
        }
    }

    public function __destruct()
    {
        if ($this->isParentProcess) {
            $this->cleanup();
        }
    }

    /**
     * Main Entry Point
     */
    public function process(FileParserInterface $parser, string $inputFile): UniqueCounter
    {
        $this->output->writeLine("Starting Parallel Processing ({$this->workerCount} workers)...");
        $this->ensureTempDirectory();

        // 1. Physically split the large file into N valid smaller files
        $partitions = $this->partitionInputFile($inputFile);

        // 2. Assign each partition to a worker
        foreach ($partitions as $workerIndex => $partitionFile) {
            $this->spawnWorker($workerIndex, $parser, $partitionFile);
        }

        // 3. Wait for all to finish
        $this->waitForWorkers();

        // 4. Merge results
        return $this->mergeResults();
    }

    /**
     * Determines file type and delegates to the correct splitter.
     */
    private function partitionInputFile(string $inputFile): array
    {
        if (!file_exists($inputFile)) {
            throw new RuntimeException("Input file not found: {$inputFile}");
        }

        $ext = mb_strtolower(pathinfo($inputFile, PATHINFO_EXTENSION));
        $this->output->writeLine("Partitioning input file (Format: {$ext})...");

        return match ($ext) {
            'csv' => $this->partitionLineBased($inputFile, hasHeader: true),
            'ndjson', 'jsonl' => $this->partitionLineBased($inputFile, hasHeader: false),
            'json' => $this->partitionJson($inputFile),
            'xml' => $this->partitionXml($inputFile),
            default => throw new RuntimeException("Parallel processing not supported for .{$ext} files"),
        };
    }

    /**
     * Handles CSV and NDJSON.
     * Reads line-by-line and distributes to temp files in Round-Robin fashion.
     */
    private function partitionLineBased(string $inputFile, bool $hasHeader): array
    {
        $source = fopen($inputFile, 'rb');

        $paths = []; // Initialize to prevent TypeError
        $handles = $this->createPartitionHandles($paths);

        // 1. Handle CSV Header
        if ($hasHeader) {
            $header = fgets($source);
            if ($header !== false) {
                foreach ($handles as $h) {
                    fwrite($h, $header);
                }
            }
        }

        // 2. Distribute lines
        $current = 0;
        while (($line = fgets($source)) !== false) {
            fwrite($handles[$current], $line);
            $current = ($current + 1) % $this->workerCount;
        }

        $this->closeHandles($source, $handles);
        return $paths;
    }

    /**
     * Handles Standard JSON (Array of Objects).
     * Streaming parse to identify objects, then writes them into new JSON Arrays in temp files.
     */
    private function partitionJson(string $inputFile): array
    {
        $source = fopen($inputFile, 'rb');

        $paths = []; // Initialize to prevent TypeError
        $handles = $this->createPartitionHandles($paths);

        // Initialize all partitions as valid JSON arrays
        foreach ($handles as $h) {
            fwrite($h, '[');
        }

        // Track if we have written to a specific partition (to handle commas)
        $hasWritten = array_fill(0, $this->workerCount, false);

        $buffer = '';
        $depth = 0;
        $inString = false;
        $current = 0;

        // Skip the opening '[' of the main array
        while (($char = fgetc($source)) !== false) {
            if (mb_trim($char) === '[') {
                break;
            }
        }

        // Stream the objects
        while (($char = fgetc($source)) !== false) {
            $buffer .= $char;

            // Toggle string state to ignore braces inside strings
            if ($char === '"' && mb_substr($buffer, -2, 1) !== '\\') {
                $inString = !$inString;
            }

            if (!$inString) {
                if ($char === '{') {
                    $depth++;
                }
                if ($char === '}') {
                    $depth--;
                }

                // End of an object at depth 0
                if ($depth === 0 && str_ends_with(mb_trim($buffer), '}')) {
                    $jsonObj = mb_trim($buffer, ", \n\r\t");

                    if (!empty($jsonObj)) {
                        // Write comma if this partition already has data
                        if ($hasWritten[$current]) {
                            fwrite($handles[$current], ',');
                        }

                        fwrite($handles[$current], $jsonObj);
                        $hasWritten[$current] = true;

                        $current = ($current + 1) % $this->workerCount;
                    }
                    $buffer = '';
                }
            }
        }

        // Close all partitions
        foreach ($handles as $h) {
            fwrite($h, ']');
        }

        $this->closeHandles($source, $handles);
        return $paths;
    }

    /**
     * Handles XML using XMLReader.
     * Iterates over children of the root element and distributes them.
     */
    private function partitionXml(string $inputFile): array
    {
        $reader = new XMLReader();
        if (!$reader->open($inputFile)) {
            throw new RuntimeException('Failed to open XML file with XMLReader');
        }

        $paths = []; // Initialize to prevent TypeError
        $handles = $this->createPartitionHandles($paths);

        // Write dummy root to all partitions
        foreach ($handles as $h) {
            fwrite($h, "<root>\n");
        }

        $current = 0;

        // 1. Move to the Root Element (e.g. <products>)
        while ($reader->read() && $reader->nodeType !== XMLReader::ELEMENT);

        // 2. Move to the first Child Element (the Item, e.g. <product>)
        if ($reader->read()) {
            while ($reader->nodeType !== XMLReader::ELEMENT) {
                if (!$reader->read()) {
                    break;
                } // End of file/no children
            }

            // 3. Iterate over siblings
            do {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    // Extract the full XML string of the current node (including attributes)
                    $xmlItem = $reader->readOuterXml();

                    fwrite($handles[$current], $xmlItem . "\n");
                    $current = ($current + 1) % $this->workerCount;

                    // Move to next sibling.
                    // Important: next() skips the current node's children, which we just read via readOuterXml
                    if (!$reader->next()) {
                        break;
                    }
                } else {
                    // Skip whitespace/comments
                    if (!$reader->read()) {
                        break;
                    }
                }
            } while ($reader->nodeType !== XMLReader::NONE);
        }

        $reader->close();

        // Close dummy roots
        foreach ($handles as $h) {
            fwrite($h, '</root>');
        }

        foreach ($handles as $h) {
            fclose($h);
        }

        return $paths;
    }

    private function spawnWorker(int $index, FileParserInterface $parser, string $partitionFile): void
    {
        $resultFile = $this->createTempFile("result_{$index}_");

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Fork failed for worker {$index}");
        }

        if ($pid === 0) {
            // --- CHILD PROCESS ---
            $this->isParentProcess = false;
            try {
                // Since the partition is a VALID file of the same type,
                // the original parser works without modification.
                $this->runWorkerLogic($parser, $partitionFile, $resultFile);
            } catch (Throwable $e) {
                fwrite(STDERR, "Worker {$index} error: " . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            exit(0);
        }

        // --- PARENT PROCESS ---
        $this->workerPids[$index] = $pid;
        $this->resultFiles[$index] = $resultFile;
    }

    private function runWorkerLogic(FileParserInterface $parser, string $inputFile, string $outputFile): void
    {
        $counter = new UniqueCounter();

        // Standard parsing logic
        foreach ($parser->parse($inputFile) as $product) {
            if ($product instanceof Product) {
                $counter->addProduct($product);
            }
        }

        $data = [];
        foreach ($counter->getCombinations() as $key => $val) {
            $data[] = [
                'product' => $val['product']->toArray(),
                'count' => $val['count'],
            ];
        }

        file_put_contents($outputFile, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function mergeResults(): UniqueCounter
    {
        $this->output->writeLine('Merging results...');
        $merged = new UniqueCounter();

        foreach ($this->resultFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $json = file_get_contents($file);
            if (!$json) {
                continue;
            }

            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            foreach ($data as $item) {
                $product = Product::fromArray($item['product']);
                $count = (int) $item['count'];

                for ($i = 0; $i < $count; $i++) {
                    $merged->addProduct($product);
                }
            }
        }
        return $merged;
    }

    // --- Helpers ---

    private function createPartitionHandles(array &$paths): array
    {
        $handles = [];
        for ($i = 0; $i < $this->workerCount; $i++) {
            $path = $this->createTempFile("part_{$i}_");
            $paths[] = $path;
            $this->partitionFiles[] = $path;
            $handles[] = fopen($path, 'w');
        }
        return $handles;
    }

    private function closeHandles($source, array $targets): void
    {
        fclose($source);
        foreach ($targets as $h) {
            fclose($h);
        }
    }

    private function ensureTempDirectory(): void
    {
        $dir = $this->getTempDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function createTempFile(string $prefix): string
    {
        return tempnam($this->getTempDir(), $prefix);
    }

    private function getTempDir(): string
    {
        return sys_get_temp_dir() . '/products_parser';
    }

    private function waitForWorkers(): void
    {
        foreach ($this->workerPids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }
    }

    private function cleanup(): void
    {
        foreach (array_merge($this->partitionFiles, $this->resultFiles) as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
}
