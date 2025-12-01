<?php

declare(strict_types=1);

namespace App\Cli;

use App\Seeders\ProductDataGenerator;
use App\Seeders\SeederFactory;
use InvalidArgumentException;
use RuntimeException;

final readonly class SeederApplication
{
    private const int EXIT_SUCCESS = 0;
    private const int EXIT_ERROR = 1;

    public function __construct(
        private SeederFactory $factory = new SeederFactory(new ProductDataGenerator()),
        private SeederOutputWriter $outputWriter = new SeederOutputWriter(),
    ) {
    }

    public function run(array $argv): int
    {
        try {
            if ($this->shouldShowHelp($argv)) {
                $this->outputWriter->writeHelp($this->factory->getSupportedTypes());
                return self::EXIT_SUCCESS;
            }

            $options = SeederOptions::fromArguments($argv);

            if (!$options->isValid()) {
                $this->outputWriter->writeError('--type and --count parameters are required');
                $this->outputWriter->writeLine('');
                $this->outputWriter->writeHelp($this->factory->getSupportedTypes());
                return self::EXIT_ERROR;
            }

            $this->validateType($options->type);
            $this->validateCount($options->count);

            return $this->generateFile($options);

        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->outputWriter->writeError($e->getMessage());
            $this->outputWriter->writeLine('Run with --help for usage information.');
            return self::EXIT_ERROR;
        }
    }

    private function shouldShowHelp(array $args): bool
    {
        if (array_any($args, fn ($arg) => $arg === '--help' || $arg === '-h')) {
            return true;
        }

        return false;
    }

    private function validateType(?string $type): void
    {
        if ($type === null) {
            throw new InvalidArgumentException('Type cannot be null');
        }

        if (!in_array($type, $this->factory->getSupportedTypes(), true)) {
            $supported = implode(', ', $this->factory->getSupportedTypes());
            throw new InvalidArgumentException(
                "Invalid type '{$type}'. Supported types: {$supported}"
            );
        }
    }

    private function validateCount(?int $count): void
    {
        if ($count === null) {
            throw new InvalidArgumentException('Count cannot be null');
        }

        if ($count <= 0) {
            throw new InvalidArgumentException(
                "Count must be a positive integer, got: {$count}"
            );
        }
    }

    private function generateFile(SeederOptions $options): int
    {
        $startTime = microtime(true);
        $type = $options->type;
        $count = $options->count;
        $outputPath = $options->getOutputFile($type);

        $this->ensureDirectoryExists(dirname($outputPath));

        $this->outputWriter->writeGenerationStart($count, $type);

        $seeder = $this->factory->create($type);
        $seeder->seed($outputPath, $count);

        $this->outputWriter->writeSuccess($count, $outputPath, $startTime);

        return self::EXIT_SUCCESS;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$directory}");
            }
        }
    }
}
