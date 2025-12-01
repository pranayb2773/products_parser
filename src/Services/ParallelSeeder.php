<?php

declare(strict_types=1);

namespace App\Services;

use App\Cli\SeederOutputWriter;
use Exception;
use RuntimeException;

final class ParallelSeeder
{
    private array $workerPids = [];
    private array $tempFiles = [];
    private string $tempDir = '';
    private bool $isParentProcess = true;

    public function __construct(
        private readonly int $workerCount,
        private readonly SeederOutputWriter $output
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
        // Only parent process should clean up temp files
        if ($this->isParentProcess) {
            $this->cleanup();
        }
    }

    /**
     * Generate data in parallel using multiple workers, then merge into single output file.
     */
    public function seed($seeder, string $outputPath, int $totalCount): void
    {
        $this->output->writeLine("Starting parallel generation with {$this->workerCount} workers...");

        // Prefer temp directory alongside final output to avoid container /tmp quirks
        $this->tempDir = $this->determineTempDirectory($outputPath);

        // Ensure temp directory exists before spawning workers
        $this->ensureTempDirectory();

        // Store seeder class name to recreate in workers
        $seederClass = get_class($seeder);

        // Calculate how many records each worker should generate
        $countPerWorker = (int) ceil($totalCount / $this->workerCount);
        $this->output->writeLine("Records per worker: {$countPerWorker}");

        // Spawn workers to generate data in parallel
        for ($workerIndex = 0; $workerIndex < $this->workerCount; $workerIndex++) {
            $workerCount = $this->calculateWorkerCount($workerIndex, $countPerWorker, $totalCount);

            if ($workerCount <= 0) {
                break; // No more work to distribute
            }

            $this->spawnWorker($workerIndex, $seederClass, $workerCount);
        }

        // Wait for all workers to complete
        $this->waitForWorkers();

        // Merge all temp files into final output
        $this->mergeFiles($outputPath, $seeder);
    }

    private function calculateWorkerCount(int $workerIndex, int $countPerWorker, int $totalCount): int
    {
        $start = $workerIndex * $countPerWorker;

        if ($start >= $totalCount) {
            return 0;
        }

        $end = min($start + $countPerWorker, $totalCount);

        return $end - $start;
    }

    private function spawnWorker(int $workerIndex, string $seederClass, int $count): void
    {
        // Pre-create temp file so parent and child share the same path
        $tempFile = $this->createTempFile($workerIndex);

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Failed to fork worker process {$workerIndex}");
        }

        if ($pid === 0) {
            // Child process - worker
            // Mark as child so it doesn't clean up temp files in destructor
            $this->isParentProcess = false;
            $this->runWorker($workerIndex, $seederClass, $tempFile, $count);
            exit(0);
        }

        // Parent process
        $this->tempFiles[$workerIndex] = $tempFile;
        $this->workerPids[$workerIndex] = $pid;
        $this->output->writeLine("Worker {$workerIndex} spawned (PID: {$pid}, Count: {$count}, TempFile: {$tempFile})");
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

    private function getTempDirectory(): string
    {
        if ($this->tempDir !== '') {
            return $this->tempDir;
        }

        // Fallback to container/system temp if not set
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

        $tempFile = tempnam($tmpDir, "seeder_{$workerIndex}_");

        if ($tempFile === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'unknown error';
            throw new RuntimeException("Failed to create temp file for worker {$workerIndex}: {$message}");
        }

        return $tempFile;
    }

    private function determineTempDirectory(string $outputPath): string
    {
        $outputDir = mb_rtrim(dirname($outputPath), DIRECTORY_SEPARATOR);

        if ($outputDir === '' || $outputDir === DIRECTORY_SEPARATOR) {
            // Fallback to system temp if we cannot infer a directory
            $base = getenv('TMPDIR') ?: sys_get_temp_dir();
            return mb_rtrim($base, DIRECTORY_SEPARATOR) . '/products_parser';
        }

        return $outputDir . DIRECTORY_SEPARATOR . '.tmp_seeder';
    }

    private function runWorker(int $workerIndex, string $seederClass, string $tempFile, int $count): void
    {
        try {
            // Double-check temp directory is ready inside the worker
            $this->ensureTempDirectory();

            // Recreate seeder instance in child process
            $seeder = $this->createSeederInstance($seederClass);

            // Each worker generates its portion to a temp file
            $seeder->seed($tempFile, $count);

        } catch (Exception $e) {
            // Log the error to stderr and exit with error code
            fwrite(STDERR, "Worker {$workerIndex} error: " . $e->getMessage() . "\n");
            fwrite(STDERR, 'Stack trace: ' . $e->getTraceAsString() . "\n");
            exit(1);
        }
    }

    private function createSeederInstance(string $seederClass)
    {
        // Recreate the seeder with its dependencies
        // All seeders take ProductDataGenerator as constructor parameter
        $generator = new \App\Seeders\ProductDataGenerator();

        return new $seederClass($generator);
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

    private function mergeFiles(string $outputPath, $seeder): void
    {
        $this->output->writeLine('Merging worker outputs into final file...');

        // Determine the file type to handle merging correctly
        $extension = mb_strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));

        match ($extension) {
            'csv', 'tsv' => $this->mergeCsvFiles($outputPath),
            'json' => $this->mergeJsonFiles($outputPath),
            'ndjson' => $this->mergeNdjsonFiles($outputPath),
            'xml' => $this->mergeXmlFiles($outputPath),
            default => throw new RuntimeException("Unsupported file type for merging: {$extension}"),
        };

        $this->output->writeLine('Merge completed successfully');
    }

    private function mergeCsvFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'wb');

        if ($output === false) {
            throw new RuntimeException("Failed to open output file: {$outputPath}");
        }

        // Set larger buffer for output
        stream_set_write_buffer($output, 1024 * 1024); // 1MB buffer

        $headerWritten = false;

        foreach ($this->tempFiles as $workerIndex => $tempFile) {
            $this->ensureTempFileAvailable($tempFile);

            $input = fopen($tempFile, 'rb');

            if ($input === false) {
                fclose($output);
                throw new RuntimeException("Failed to open temp file: {$tempFile}");
            }

            // Set larger buffer for input
            stream_set_read_buffer($input, 1024 * 1024); // 1MB buffer

            // Read and write header from first file only
            if (!$headerWritten) {
                $header = fgets($input);
                if ($header !== false) {
                    fwrite($output, $header);
                    $headerWritten = true;
                }
            } else {
                // Skip header line in subsequent files
                fgets($input);
            }

            // Stream copy the rest of the file using large chunks
            while (!feof($input)) {
                $chunk = fread($input, 8192 * 1024); // 8MB chunks
                if ($chunk !== false && $chunk !== '') {
                    fwrite($output, $chunk);
                }
            }

            fclose($input);
        }

        fclose($output);
    }

    private function mergeJsonFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'w');

        if ($output === false) {
            throw new RuntimeException("Failed to open output file: {$outputPath}");
        }

        fwrite($output, "[\n");

        $firstItem = true;

        foreach ($this->tempFiles as $workerIndex => $tempFile) {
            $this->ensureTempFileAvailable($tempFile);

            $content = file_get_contents($tempFile);

            if ($content === false) {
                fclose($output);
                throw new RuntimeException("Failed to read temp file: {$tempFile}");
            }

            $data = json_decode($content, true);

            if (!is_array($data)) {
                fclose($output);
                throw new RuntimeException("Invalid JSON in temp file: {$tempFile}");
            }

            foreach ($data as $item) {
                if (!$firstItem) {
                    fwrite($output, ",\n");
                }

                fwrite($output, json_encode($item, JSON_THROW_ON_ERROR));
                $firstItem = false;
            }
        }

        fwrite($output, "\n]\n");
        fclose($output);
    }

    private function mergeNdjsonFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'wb');

        if ($output === false) {
            throw new RuntimeException("Failed to open output file: {$outputPath}");
        }

        // Set larger buffer for output
        stream_set_write_buffer($output, 1024 * 1024); // 1MB buffer

        foreach ($this->tempFiles as $workerIndex => $tempFile) {
            $this->ensureTempFileAvailable($tempFile);

            $input = fopen($tempFile, 'rb');

            if ($input === false) {
                fclose($output);
                throw new RuntimeException("Failed to open temp file: {$tempFile}");
            }

            // Set larger buffer for input
            stream_set_read_buffer($input, 1024 * 1024); // 1MB buffer

            // Stream copy using large chunks
            while (!feof($input)) {
                $chunk = fread($input, 8192 * 1024); // 8MB chunks
                if ($chunk !== false && $chunk !== '') {
                    fwrite($output, $chunk);
                }
            }

            fclose($input);
        }

        fclose($output);
    }

    private function mergeXmlFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'wb');

        if ($output === false) {
            throw new RuntimeException("Failed to open output file: {$outputPath}");
        }

        // Set larger buffer for output
        stream_set_write_buffer($output, 1024 * 1024); // 1MB buffer

        // Write XML header
        fwrite($output, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<products>\n");

        foreach ($this->tempFiles as $workerIndex => $tempFile) {
            $this->ensureTempFileAvailable($tempFile);

            $input = fopen($tempFile, 'rb');

            if ($input === false) {
                fclose($output);
                throw new RuntimeException("Failed to open temp file: {$tempFile}");
            }

            // Set larger buffer for input
            stream_set_read_buffer($input, 1024 * 1024); // 1MB buffer

            // Skip XML declaration and opening <products> tag
            $foundProductsTag = false;
            while (($line = fgets($input)) !== false) {
                if (str_contains($line, '<products>')) {
                    $foundProductsTag = true;
                    break;
                }
            }

            if (!$foundProductsTag) {
                fclose($input);
                fclose($output);
                throw new RuntimeException("Invalid XML format in temp file: {$tempFile}");
            }

            // Read and write content in large chunks, but stop before closing tag
            $buffer = '';
            while (!feof($input)) {
                $chunk = fread($input, 8192 * 1024); // 8MB chunks
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $buffer .= $chunk;

                // Check if we have the closing tag in buffer
                $closingTagPos = mb_strpos($buffer, '</products>');
                if ($closingTagPos !== false) {
                    // Write everything before the closing tag
                    fwrite($output, mb_substr($buffer, 0, $closingTagPos));
                    break;
                }

                // If buffer is large enough and no closing tag yet, write most of it
                if (mb_strlen($buffer) > 16 * 1024 * 1024) { // Keep last 16MB in buffer
                    $writeSize = mb_strlen($buffer) - (1024 * 1024); // Keep 1MB
                    fwrite($output, mb_substr($buffer, 0, $writeSize));
                    $buffer = mb_substr($buffer, $writeSize);
                }
            }

            // Write any remaining buffer (excluding closing tag)
            if ($buffer !== '' && !str_contains($buffer, '</products>')) {
                fwrite($output, $buffer);
            }

            fclose($input);
        }

        // Write closing tag
        fwrite($output, "</products>\n");
        fclose($output);
    }

    private function ensureTempFileAvailable(string $tempFile): void
    {
        clearstatcache(true, $tempFile);

        if (file_exists($tempFile)) {
            return;
        }

        // Retry a few times in case of delayed host/volume sync
        $attempts = 3;
        for ($i = 0; $i < $attempts; $i++) {
            usleep(200_000); // 200ms
            clearstatcache(true, $tempFile);
            if (file_exists($tempFile)) {
                return;
            }
        }

        $dir = dirname($tempFile);
        $listing = @scandir($dir) ?: [];
        $listing = array_slice(array_filter($listing, fn ($f) => $f !== '.' && $f !== '..'), 0, 20);

        throw new RuntimeException("Temp file does not exist after retries: {$tempFile}; dir contents: " . implode(', ', $listing));
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
