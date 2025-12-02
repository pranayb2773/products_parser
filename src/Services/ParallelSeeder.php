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
    }

    public function __destruct()
    {
        if ($this->isParentProcess) {
            $this->cleanup();
        }
    }

    public function seed($seeder, string $outputPath, int $totalCount): void
    {
        $this->output->writeLine("Starting parallel generation with {$this->workerCount} workers...");
        $this->tempDir = $this->determineTempDirectory($outputPath);
        $this->ensureTempDirectory();

        $seederClass = get_class($seeder);
        $countPerWorker = (int) ceil($totalCount / $this->workerCount);
        $this->output->writeLine("Records per worker: {$countPerWorker}");

        // Spawn workers
        for ($workerIndex = 0; $workerIndex < $this->workerCount; $workerIndex++) {
            $workerCount = $this->calculateWorkerCount($workerIndex, $countPerWorker, $totalCount);
            if ($workerCount <= 0) {
                break;
            }
            $this->spawnWorker($workerIndex, $seederClass, $workerCount);
        }

        $this->waitForWorkers();
        $this->mergeFiles($outputPath, $seeder);
    }

    private function calculateWorkerCount(int $workerIndex, int $countPerWorker, int $totalCount): int
    {
        $start = $workerIndex * $countPerWorker;
        if ($start >= $totalCount) {
            return 0;
        }
        return min($start + $countPerWorker, $totalCount) - $start;
    }

    private function spawnWorker(int $workerIndex, string $seederClass, int $count): void
    {
        $tempFile = $this->createTempFile($workerIndex);
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Fork failed');
        }

        if ($pid === 0) {
            // Child
            $this->isParentProcess = false;
            $this->runWorker($workerIndex, $seederClass, $tempFile, $count);
            exit(0);
        }

        // Parent
        $this->tempFiles[$workerIndex] = $tempFile;
        $this->workerPids[$workerIndex] = $pid;
        $this->output->writeLine("Worker {$workerIndex} spawned (PID: {$pid})");
    }

    private function runWorker(int $workerIndex, string $seederClass, string $tempFile, int $count): void
    {
        try {
            $generator = new \App\Seeders\ProductDataGenerator();
            $seeder = new $seederClass($generator);
            $seeder->seed($tempFile, $count);
        } catch (Exception $e) {
            fwrite(STDERR, "Worker {$workerIndex} failed: " . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    // --- OPTIMIZED MERGE LOGIC START ---

    private function mergeFiles(string $outputPath, $seeder): void
    {
        $this->output->writeLine('Merging worker outputs (Zero-Copy Mode)...');
        $start = microtime(true);

        $extension = mb_strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));

        match ($extension) {
            'csv', 'tsv' => $this->mergeCsvFiles($outputPath),
            'json' => $this->mergeJsonFiles($outputPath),
            'ndjson' => $this->mergeNdjsonFiles($outputPath),
            'xml' => $this->mergeXmlFiles($outputPath),
            default => throw new RuntimeException("Unsupported file type: {$extension}"),
        };

        $duration = round(microtime(true) - $start, 2);
        $this->output->writeLine("Merge completed in {$duration}s");
    }

    private function mergeCsvFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'wb');

        foreach ($this->tempFiles as $index => $tempFile) {
            $input = fopen($tempFile, 'rb');

            // For files after the first one, skip the header line
            if ($index > 0) {
                fgets($input);
            }

            // High-speed stream copy
            stream_copy_to_stream($input, $output);

            fclose($input);
        }
        fclose($output);
    }

    private function mergeNdjsonFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'wb');
        foreach ($this->tempFiles as $tempFile) {
            $input = fopen($tempFile, 'rb');
            stream_copy_to_stream($input, $output);
            fclose($input);
        }
        fclose($output);
    }

    /**
     * Optimizes JSON merging by raw byte manipulation.
     * Assumes workers output: [ {obj}, {obj} ]
     * We strip [ and ] and join them with commas.
     */
    private function mergeJsonFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'wb');
        fwrite($output, "[\n"); // Start main array

        foreach ($this->tempFiles as $index => $tempFile) {
            $fileSize = filesize($tempFile);
            if ($fileSize < 2) {
                continue;
            } // Skip empty/broken files

            $input = fopen($tempFile, 'rb');

            // 1. Skip the first char '['
            fseek($input, 1);

            // 2. Calculate length to read (Size - Start '[' - End ']')
            $bytesToRead = $fileSize - 2;

            if ($index > 0) {
                fwrite($output, ",\n"); // Add comma between worker chunks
            }

            // 3. Stream the middle content directly
            if ($bytesToRead > 0) {
                stream_copy_to_stream($input, $output, $bytesToRead);
            }

            fclose($input);
        }

        fwrite($output, "\n]"); // Close main array
        fclose($output);
    }

    /**
     * Optimizes XML merging by skipping headers/footers via seeking.
     * Assumes workers output: <?xml ... <products> ...ITEMS... </products>
     */
    private function mergeXmlFiles(string $outputPath): void
    {
        $output = fopen($outputPath, 'wb');

        // Write global header
        fwrite($output, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<products>\n");

        $closingTag = '</products>';
        $closingTagLen = mb_strlen($closingTag);

        foreach ($this->tempFiles as $tempFile) {
            $input = fopen($tempFile, 'rb');

            // 1. Find start of content (after <products>)
            $startPos = 0;
            while (($line = fgets($input)) !== false) {
                $pos = mb_strpos($line, '<products>');
                if ($pos !== false) {
                    $startPos = ftell($input); // Pointing to line after <products> or text immediately after
                    break;
                }
            }

            // 2. Calculate length to read (Total - Start - ClosingTag - formatting newline)
            // We assume the file ends with </products> or </products>\n
            $stat = fstat($input);
            $endPos = $stat['size'];

            // Seek from end to find where </products> starts to be safe
            // Scan last 100 bytes to find the tag
            fseek($input, -100, SEEK_END);
            $tail = fread($input, 100);
            $tagPos = mb_strrpos($tail, $closingTag);

            if ($tagPos !== false) {
                // Determine absolute position of closing tag
                $realEndPos = ($stat['size'] - 100) + $tagPos;
                $bytesToCopy = $realEndPos - $startPos;

                // Go back to start of content
                fseek($input, $startPos);

                // Copy exact bytes
                if ($bytesToCopy > 0) {
                    stream_copy_to_stream($input, $output, $bytesToCopy);
                }
            }

            fclose($input);
        }

        fwrite($output, "</products>\n");
        fclose($output);
    }

    // --- OPTIMIZED MERGE LOGIC END ---

    private function ensureTempDirectory(): void
    {
        $tmpDir = $this->getTempDirectory();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }
    }

    private function getTempDirectory(): string
    {
        if ($this->tempDir !== '') {
            return $this->tempDir;
        }
        return sys_get_temp_dir() . '/seeder_tmp';
    }

    private function determineTempDirectory(string $outputPath): string
    {
        $dir = dirname($outputPath);
        return is_writable($dir) ? $dir . '/.tmp_seeder' : $this->getTempDirectory();
    }

    private function createTempFile(int $index): string
    {
        return tempnam($this->getTempDirectory(), "worker_{$index}_");
    }

    private function waitForWorkers(): void
    {
        foreach ($this->workerPids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    private function cleanup(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        if ($this->tempDir && is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }
}
