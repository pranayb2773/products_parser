<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Contracts\FileParserInterface;
use App\Enums\CsvDelimiter;
use App\Mapping\FieldMapper;
use RuntimeException;

final readonly class ParserFactory
{
    public function __construct(
        private FieldMapper $fieldMapper = new FieldMapper(),
    ) {
    }

    public function createFromFile(string $filePath): FileParserInterface
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => new CsvParser($this->fieldMapper, CsvDelimiter::COMMA),
            'tsv' => new CsvParser($this->fieldMapper, CsvDelimiter::TAB),
            default => throw new RuntimeException("Unsupported file format: {$extension}"),
        };
    }
}
