<?php

declare(strict_types=1);

namespace App\Cli;

use App\Models\Product;

final readonly class ParserOutputWriter
{
    private const int SEPARATOR_LENGTH = 80;
    private const string NEW_LINE = "\n";

    public function writeLine(string $message): void
    {
        echo $message . self::NEW_LINE;
    }

    public function writeSeparator(): void
    {
        $this->writeLine(str_repeat('-', self::SEPARATOR_LENGTH));
    }

    public function writeError(string $message): void
    {
        $this->writeLine("Error: {$message}");
    }

    public function writeFileHeader(string $filePath): void
    {
        $fileType = mb_strtoupper(pathinfo($filePath, PATHINFO_EXTENSION));
        $this->writeLine("Parsing {$fileType} file: {$filePath}");
        $this->writeSeparator();
    }

    public function writeProduct(Product $product): void
    {
        $this->writeLine((string) $product);
    }

    public function writeStatistics(int $totalProducts, int $uniqueCount): void
    {
        $this->writeSeparator();
        $this->writeLine("Total products processed: {$totalProducts}");
        $this->writeLine("Unique combinations: {$uniqueCount}");
    }

    public function writeTiming(float $start, float $end, int $productCount): void
    {
        $elapsed = $end - $start;
        $rate = $elapsed > 0 ? $productCount / $elapsed : $productCount;
        $this->writeLine(sprintf('Elapsed: %.3f seconds (%.2f items/sec)', $elapsed, $rate));
    }

    public function writeOutputConfirmation(string $outputPath): void
    {
        $outputFormat = mb_strtoupper(pathinfo($outputPath, PATHINFO_EXTENSION));
        $this->writeLine("Unique combinations written to {$outputFormat} file: {$outputPath}");
    }

    public function writeHelp(): void
    {
        $this->writeLine(
            <<<'HELP'
            Usage: php parser.php --file=<input_file> [--unique-combinations=<output_file>] [--parallel=<workers>]

            Options:
              --file=<path>                  Path to the input file (required)
                                             Supported formats: CSV, TSV, JSON, XML
              --unique-combinations=<path>   Path to the output file for unique combinations (optional)
                                             Format is auto-detected from file extension: .csv, .json, .xml
              --parallel=<number>            Number of parallel workers (optional, default: 1)
                                             Use multiple workers for faster processing of large files
                                             Requires PCNTL extension
              --help, -h                     Display this help message

            Examples:
              php parser.php --file=data/input/products_comma_separated.csv --unique-combinations=data/output/combination_count.csv
              php parser.php --file=data/input/products.json --unique-combinations=data/output/combination_count.json
              php parser.php --file=data/input/products.xml --unique-combinations=data/output/combination_count.xml
              php parser.php --file=data/input/products.csv --unique-combinations=data/output/results.json --parallel=4

            HELP
        );
    }
}
