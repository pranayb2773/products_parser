<?php

declare(strict_types=1);

namespace App\Seeders;

use App\Contracts\SeederInterface;
use App\Enums\CsvDelimiter;
use InvalidArgumentException;

final readonly class SeederFactory
{
    public function __construct(
        private ProductDataGenerator $generator,
    ) {
    }

    /**
     * Create a seeder for the specified file type
     */
    public function create(string $type): SeederInterface
    {
        return match (mb_strtolower($type)) {
            'csv' => new CsvSeeder($this->generator, CsvDelimiter::COMMA),
            'tsv' => new CsvSeeder($this->generator, CsvDelimiter::TAB),
            'json' => new JsonSeeder($this->generator),
            'ndjson' => new NdjsonSeeder($this->generator),
            'xml' => new XmlSeeder($this->generator),
            default => throw new InvalidArgumentException(
                "Unsupported seeder type: {$type}. Supported types: csv, tsv, json, ndjson, xml"
            ),
        };
    }

    public function getSupportedTypes(): array
    {
        return ['csv', 'tsv', 'json', 'ndjson', 'xml'];
    }
}
