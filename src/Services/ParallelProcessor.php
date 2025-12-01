<?php

declare(strict_types=1);

namespace App\Services;

use App\Cli\ParserOutputWriter;
use App\Contracts\FileParserInterface;
use App\Models\Product;
use JsonException;
use RuntimeException;

final class ParallelProcessor
{
    private array $workerPids = [];
    private array $tempFiles = [];

    public function __construct(
        private readonly int $workerCount,
        private readonly ParserOutputWriter $output
    ) {
        if (!extension_loaded('pcntl')) {
            throw new RuntimeException('PCNTL extension is required for parallel processing');
        }

        if ($workerCount < 1) {
            throw new RuntimeException('Worker count must be at least 1');
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    /**
     * Process file in parallel using multiple workers.
     *
     * @throws JsonException
     */
    public function process(FileParserInterface $parser, string $inputFile): UniqueCounter
    {
        $this->output->writeLine("Starting parallel processing with {$this->workerCount} workers...");

        // Ensure temp directory exists before spawning workers
        $this->ensureTempDirectory();

        // First pass: count total products to distribute work
        $products = iterator_to_array($parser->parse($inputFile));
        $totalProducts = count($products);
        $chunkSize = (int) ceil($totalProducts / $this->workerCount);

        $this->output->writeLine("Total products: {$totalProducts}");
        $this->output->writeLine("Chunk size per worker: {$chunkSize}");

        // Split products into chunks
        $chunks = array_chunk($products, $chunkSize);

        // Spawn workers
        foreach ($chunks as $workerIndex => $chunk) {
            $this->spawnWorker($workerIndex, $chunk);
        }

        // Wait for all workers to complete
        $this->waitForWorkers();

        // Merge results from all workers
        return $this->mergeResults();
    }

    /**
     * @throws JsonException
     */
    private function spawnWorker(int $workerIndex, array $products): void
    {
        // Pre-create temp file so parent and child share the same path
        $tempFile = $this->createTempFile($workerIndex);

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Failed to fork worker process {$workerIndex}");
        }

        if ($pid === 0) {
            // Child process - worker
            $this->runWorker($workerIndex, $products, $tempFile);
            exit(0);
        }

        // Parent process
        $this->tempFiles[$workerIndex] = $tempFile;
        $this->workerPids[$workerIndex] = $pid;
        $this->output->writeLine("Worker {$workerIndex} spawned (PID: {$pid})");
    }

    private function ensureTempDirectory(): void
    {
        $tmpDir = $this->getTempDirectory();

        if (!is_dir($tmpDir)) {
            if (!mkdir($tmpDir, 0777, true)) {
                throw new RuntimeException("Failed to create temp directory: {$tmpDir}");
            }
        }

        if (!is_writable($tmpDir)) {
            throw new RuntimeException("Temp directory is not writable: {$tmpDir}");
        }

        $this->output->writeLine("Temp directory ready: {$tmpDir}");
    }

    /**
     * Worker process logic.
     *
     * @throws JsonException
     */
    private function runWorker(int $workerIndex, array $products, string $tempFile): void
    {
        // Ensure temp directory exists inside worker context
        $this->ensureTempDirectory();

        $uniqueCounter = new UniqueCounter();

        foreach ($products as $product) {
            if ($product instanceof Product) {
                $uniqueCounter->addProduct($product);
            }
        }

        // Write results to temp file
        $combinations = $uniqueCounter->getCombinations();
        $data = [];

        foreach ($combinations as $key => $combinationData) {
            $data[] = [
                'key' => $key,
                'product' => $combinationData['product']->toArray(),
                'count' => $combinationData['count'],
            ];
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR);

        if (file_put_contents($tempFile, $json) === false) {
            throw new RuntimeException("Worker {$workerIndex} failed to write results");
        }
    }

    private function getTempDirectory(): string
    {
        // Prefer container/system temp so writes are not blocked by bind mounts
        $base = getenv('TMPDIR') ?: sys_get_temp_dir();
        return mb_rtrim($base, DIRECTORY_SEPARATOR) . '/products_parser';
    }

    private function createTempFile(int $workerIndex): string
    {
        $tmpDir = $this->getTempDirectory();

        if (!is_dir($tmpDir)) {
            if (!mkdir($tmpDir, 0777, true)) {
                throw new RuntimeException("Failed to create temp directory: {$tmpDir}");
            }
        }

        $tempFile = tempnam($tmpDir, "parser_{$workerIndex}_");

        if ($tempFile === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'unknown error';
            throw new RuntimeException("Failed to create temp file for worker {$workerIndex}: {$message}");
        }

        return $tempFile;
    }

    private function waitForWorkers(): void
    {
        foreach ($this->workerPids as $workerIndex => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);

            if (pcntl_wifexited($status)) {
                $exitCode = pcntl_wexitstatus($status);

                if ($exitCode === 0) {
                    $this->output->writeLine("Worker {$workerIndex} completed successfully");
                } else {
                    throw new RuntimeException("Worker {$workerIndex} exited with code {$exitCode}");
                }
            } else {
                throw new RuntimeException("Worker {$workerIndex} terminated abnormally");
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function mergeResults(): UniqueCounter
    {
        $this->output->writeLine('Merging results from all workers...');

        $merged = new UniqueCounter();

        foreach ($this->tempFiles as $workerIndex => $tempFile) {
            if (!file_exists($tempFile)) {
                throw new RuntimeException("Worker {$workerIndex} temp file not found: {$tempFile}");
            }

            $json = file_get_contents($tempFile);

            if ($json === false) {
                throw new RuntimeException("Failed to read worker {$workerIndex} results");
            }

            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                throw new RuntimeException("Worker {$workerIndex} results are not valid JSON array");
            }

            foreach ($data as $item) {
                $product = Product::fromArray($item['product']);
                $count = (int) $item['count'];

                // Add product multiple times to match the count
                for ($i = 0; $i < $count; $i++) {
                    $merged->addProduct($product);
                }
            }
        }

        $this->output->writeLine('Results merged successfully');

        return $merged;
    }

    private function cleanup(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        $this->tempFiles = [];
        $this->workerPids = [];
    }
}
