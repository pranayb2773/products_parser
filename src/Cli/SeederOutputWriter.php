<?php

declare(strict_types=1);

namespace App\Cli;

final readonly class SeederOutputWriter
{
    private const string NEW_LINE = "\n";

    public function writeLine(string $message): void
    {
        echo $message . self::NEW_LINE;
    }

    public function writeError(string $message): void
    {
        $this->writeLine("Error: {$message}");
    }

    public function writeGenerationStart(int $count, string $type): void
    {
        $this->writeLine("Generating {$count} products in {$type} format...");
    }

    public function writeSuccess(int $count, string $outputPath, float $startTime): void
    {
        $elapsed = microtime(true) - $startTime;
        $fileSize = filesize($outputPath);
        $fileSizeMB = round($fileSize / 1024 / 1024, 2);

        $this->writeLine("✓ Successfully generated {$count} products");
        $this->writeLine("  File: {$outputPath}");
        $this->writeLine("  Size: {$fileSizeMB} MB");
        $this->writeLine('  Time: ' . round($elapsed, 2) . ' seconds');
    }

    public function writeHelp(array $supportedTypes): void
    {
        $supported = implode(', ', $supportedTypes);

        $this->writeLine('Product Data Generator');
        $this->writeLine('');
        $this->writeLine('Usage:');
        $this->writeLine('  php seeder.php --type=<format> --count=<number> [--output=<path>] [--parallel=<workers>]');
        $this->writeLine('  php seeder.php --help');
        $this->writeLine('');
        $this->writeLine('Arguments:');
        $this->writeLine('  --type=<format>      File format to generate (required)');
        $this->writeLine("                       Supported: {$supported}");
        $this->writeLine('');
        $this->writeLine('  --count=<number>     Number of products to generate (required)');
        $this->writeLine('                       Must be a positive integer');
        $this->writeLine('');
        $this->writeLine('  --output=<path>      Custom output file path (optional)');
        $this->writeLine('                       Default: examples/products.<type>');
        $this->writeLine('');
        $this->writeLine('  --parallel=<number>  Number of parallel workers (optional, default: 1)');
        $this->writeLine('                       Use multiple workers for faster generation');
        $this->writeLine('                       Requires PCNTL extension');
        $this->writeLine('');
        $this->writeLine('  --help, -h           Show this help message');
        $this->writeLine('');
        $this->writeLine('Examples:');
        $this->writeLine('  php seeder.php --type=csv --count=1000');
        $this->writeLine('  php seeder.php --type=json --count=50000');
        $this->writeLine('  php seeder.php --type=xml --count=100 --output=custom/data.xml');
        $this->writeLine('  php seeder.php --type=ndjson --count=10000');
        $this->writeLine('  php seeder.php --type=csv --count=1000000 --parallel=8');
    }
}
