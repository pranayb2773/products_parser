<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Contracts\FileParserInterface;
use App\Enums\CsvDelimiter;
use App\Enums\CsvFormat;
use App\Mapping\FieldMapper;
use App\Models\Product;
use Generator;
use RuntimeException;

final readonly class CsvParser implements FileParserInterface
{
    private const int MAX_LINE_LENGTH = 0;

    public function __construct(
        private FieldMapper $fieldMapper = new FieldMapper(),
        private ?CsvDelimiter $delimiter = null
    ) {
    }

    public function parse(string $filePath): Generator
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("File not found or not readable: {$filePath}");
        }

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Failed to open file: {$filePath}");
        }

        try {
            $delimiter = $this->delimiter ?? $this->detectDelimiter($handle);

            $headers = fgetcsv($handle, self::MAX_LINE_LENGTH, $delimiter->value, CsvFormat::ENCLOSURE->value, CsvFormat::ESCAPE->value);

            if ($headers === false) {
                throw new RuntimeException("Failed to read headers from file: {$filePath}");
            }

            $headers = $this->cleanHeaders($headers);
            $rowNumber = 1;

            while (($row = fgetcsv($handle, self::MAX_LINE_LENGTH, $delimiter->value, CsvFormat::ENCLOSURE->value, CsvFormat::ESCAPE->value)) !== false) {
                $rowNumber++;

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                try {
                    $mappedData = $this->fieldMapper->mapRow($headers, $row);
                    yield Product::fromArray($mappedData);
                } catch (RuntimeException $e) {
                    throw new RuntimeException(
                        "Error processing row {$rowNumber}: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function detectDelimiter($handle): CsvDelimiter
    {
        $position = ftell($handle);
        $firstLine = fgets($handle);

        // Reset pointer immediately
        fseek($handle, $position);

        if ($firstLine === false || $firstLine === '') {
            return CsvDelimiter::COMMA;
        }

        $commaCount = mb_substr_count($firstLine, CsvDelimiter::COMMA->value);
        $tabCount = mb_substr_count($firstLine, CsvDelimiter::TAB->value);

        return $tabCount > $commaCount ? CsvDelimiter::TAB : CsvDelimiter::COMMA;
    }

    private function cleanHeaders(array $headers): array
    {
        return array_map(
            static fn (string $header): string => mb_trim($header, " \t\n\r\0\x0B\""),
            $headers
        );
    }

    private function isEmptyRow(array $row): bool
    {
        if (count($row) === 1 && mb_trim($row[0] ?? '') === '') {
            return true;
        }

        // Check if all cells are empty
        foreach ($row as $cell) {
            if (mb_trim($cell ?? '') !== '') {
                return false;
            }
        }

        return true;
    }
}
