<?php

declare(strict_types=1);

namespace App\Seeders;

use App\Contracts\SeederInterface;
use App\Enums\CsvDelimiter;
use App\Enums\CsvFormat;
use RuntimeException;

final readonly class CsvSeeder implements SeederInterface
{
    public function __construct(
        private ProductDataGenerator $generator,
        private CsvDelimiter $csvDelimiter = CsvDelimiter::COMMA
    ) {
    }

    public function seed(string $outputPath, int $count): void
    {
        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$outputPath} for writing");
        }

        try {
            // Write header row
            fputcsv($handle, $this->generator->getHeaders(), $this->csvDelimiter, CsvFormat::ENCLOSURE->value, CsvFormat::ESCAPE->value);

            // Write data row
            for ($i = 0; $i < $count; $i++) {
                $product = $this->generator->generate($i);
                fputcsv($handle, array_values($product), $this->csvDelimiter, CsvFormat::ENCLOSURE->value, CsvFormat::ESCAPE->value);
            }
        } finally {
            fclose($handle);
        }
    }

    public function getExtension(): string
    {
        return $this->csvDelimiter === CsvDelimiter::TAB ? 'tsv' : 'csv';
    }
}
