<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Contracts\FileParserInterface;
use App\Mapping\FieldMapper;
use App\Models\Product;
use Generator;
use JsonException;
use RuntimeException;

final class JsonParser implements FileParserInterface
{
    private const int BUFFER_SIZE = 1024; // 1KB buffered reads

    public function __construct(
        private readonly FieldMapper $fieldMapper = new FieldMapper(),
    ) {
    }

    public function parse(string $filePath): Generator
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new RuntimeException("File is not readable: {$filePath}}");
        }

        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Failed to open file: {$filePath}");
        }

        try {
            if ($this->isNdJson($handle)) {
                yield from $this->streamNdJson($handle);
                return;
            }

            $firstChar = $this->readNextNonWhitespace($handle);

            if ($firstChar === null) {
                return;
            }

            if ($firstChar === '[') {
                yield from $this->streamArray($handle);
                return;
            }

            if ($firstChar === '{') {
                yield from $this->streamWrappedObject($handle);
                return;
            }

            throw new RuntimeException('JSON must start with array or object');
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in file {$filePath}: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws JsonException
     */
    private function streamWrappedObject($handle): Generator
    {
        $foundStreamedArray = false;

        while (true) {
            $char = $this->readNextNonWhitespace($handle, allowNull: true);

            if ($char === null) {
                throw new RuntimeException('Unexpected end of JSON');
            }

            if ($char === '}') {
                break;
            }

            if ($char === '"') {
                $currentKey = $this->readJsonString($handle);
                $this->expectNextNonWhitespace($handle, ':');

                $valueStart = $this->readNextNonWhitespace($handle);

                if ($valueStart === null) {
                    throw new RuntimeException('Unexpected end of JSON');
                }

                if (in_array($currentKey, ['products', 'items', 'data'], true) && $valueStart === '[') {
                    $foundStreamedArray = true;
                    yield from $this->streamArray($handle);
                } else {
                    $this->skipJsonValue($handle, $valueStart);
                }

                $next = $this->readNextNonWhitespace($handle, allowNull: true);

                if ($next === null) {
                    throw new RuntimeException('Unexpected end of JSON object');
                }

                if ($next === '}') {
                    break;
                }

                if ($next !== ',') {
                    throw new RuntimeException('Invalid JSON object structure');
                }
            } else {
                throw new RuntimeException('Invalid JSON: expected string key');
            }
        }

        if (!$foundStreamedArray) {
            yield from $this->streamSingleObject($handle);
        }
    }

    private function streamArray($handle): Generator
    {
        $started = false;
        $lastHeaders = null;

        while (true) {
            $char = $started ? $this->readNextNonWhitespace($handle) : $this->readNextNonWhitespace($handle, allowNull: true);
            $started = true;

            if ($char === null) {
                throw new RuntimeException('Unexpected end of JSON array');
            }

            if ($char === ']') {
                break;
            }

            $value = $this->readJsonValue($handle, $char);
            $decoded = $this->decodeJsonToArray($value);

            try {
                $mappedData = $this->mapDecoded($decoded, $lastHeaders);
                $lastHeaders = array_keys($decoded);
                yield Product::fromArray($mappedData);
            } catch (RuntimeException $e) {
                $index = $this->safeIndexFromHandle($handle);
                throw new RuntimeException("Error processing product {$index}: " . $e->getMessage(), 0, $e);
            }

            $separator = $this->readNextNonWhitespace($handle);

            if ($separator === ']') {
                break;
            }

            if ($separator !== ',') {
                throw new RuntimeException('Invalid JSON array separator');
            }
        }
    }

    private function readJsonValue($handle, string $firstChar): string
    {
        $buffer = $firstChar;
        $depth = ($firstChar === '{' || $firstChar === '[') ? 1 : 0;
        $inString = $firstChar === '"';
        $escape = false;

        $chunk = '';
        $pos = 0;
        $len = 0;

        while (true) {
            if ($pos >= $len) {
                $chunk = fread($handle, self::BUFFER_SIZE);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Unexpected end of JSON value');
                }
                $pos = 0;
                $len = mb_strlen($chunk);
            }

            $char = $chunk[$pos++];
            $buffer .= $char;

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($char === '\\') {
                    $escape = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            }

            if ($depth === 0) {
                $remaining = $len - $pos;
                if ($remaining > 0) {
                    fseek($handle, -$remaining, SEEK_CUR);
                }
                break;
            }
        }

        return $buffer;
    }

    private function skipJsonValue($handle, string $firstChar): void
    {
        $this->readJsonValue($handle, $firstChar);
    }

    private function streamSingleObject($handle): Generator
    {
        $value = '{' . $this->readJsonValue($handle, '{');
        $decoded = $this->decodeJsonToArray($value);

        if ($this->isAssociativeArray($decoded)) {
            $mappedData = $this->fieldMapper->mapRow(array_keys($decoded), array_values($decoded));
            yield Product::fromArray($mappedData);
            return;
        }

        throw new RuntimeException('JSON must contain an array of products');
    }

    /**
     * @throws JsonException
     */
    private function readJsonString($handle): string
    {
        $buffer = '';
        $escape = false;

        while (true) {
            $chunk = fread($handle, self::BUFFER_SIZE);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unexpected end of JSON string');
            }

            $length = mb_strlen($chunk);

            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];

                if ($escape) {
                    $buffer .= $char;
                    $escape = false;
                    continue;
                }

                if ($char === '\\') {
                    $buffer .= $char;
                    $escape = true;
                    continue;
                }

                if ($char === '"') {
                    $remaining = $length - $i - 1;
                    if ($remaining > 0) {
                        fseek($handle, -$remaining, SEEK_CUR);
                    }
                    return json_decode('"' . $buffer . '"', true, 512, JSON_THROW_ON_ERROR);
                }

                $buffer .= $char;
            }
        }
    }

    private function readNextNonWhitespace($handle, bool $allowNull = false): ?string
    {
        while (true) {
            $chunk = fread($handle, self::BUFFER_SIZE);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $length = mb_strlen($chunk);
            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];
                if (!ctype_space($char)) {
                    $remaining = $length - $i - 1;
                    if ($remaining > 0) {
                        fseek($handle, -$remaining, SEEK_CUR);
                    }
                    return $char;
                }
            }
        }

        if ($allowNull) {
            return null;
        }

        throw new RuntimeException('Unexpected end of file');
    }

    private function expectNextNonWhitespace($handle, string $expected): void
    {
        $char = $this->readNextNonWhitespace($handle);

        if ($char !== $expected) {
            throw new RuntimeException("Invalid JSON: expected '{$expected}'");
        }
    }

    private function safeIndexFromHandle($handle): string
    {
        $position = ftell($handle);
        return $position === false ? 'at unknown position' : "near byte {$position}";
    }

    private function mapDecoded(array $decoded, ?array $lastHeaders): array
    {
        if (array_keys($decoded) === $lastHeaders) {
            // Keys unchanged; reuse a header list and only collect values
            return $this->fieldMapper->mapRow($lastHeaders, array_values($decoded));
        }

        $headers = [];
        $values = [];

        foreach ($decoded as $key => $value) {
            $headers[] = $key;
            $values[] = $value;
        }

        return $this->fieldMapper->mapRow($headers, $values);
    }

    private function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Detect newline-delimited JSON by peeking at the first non-whitespace line.
     */
    private function isNdJson($handle): bool
    {
        $pos = ftell($handle);
        if ($pos === false) {
            return false;
        }

        $line = fgets($handle);
        fseek($handle, $pos);

        if ($line === false) {
            return false;
        }

        $trimmed = mb_ltrim($line);
        return $trimmed !== '' && $trimmed[0] === '{' && str_contains($line, '}');
    }

    /**
     * Stream newline-delimited JSON (one object per line).
     */
    private function streamNdJson($handle): Generator
    {
        while (($line = fgets($handle)) !== false) {
            $line = mb_trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = $this->decodeJsonToArray($line);

            if (!$this->isAssociativeArray($decoded)) {
                throw new RuntimeException('NDJSON line must be an object');
            }

            $mappedData = $this->mapDecoded($decoded, null);
            yield Product::fromArray($mappedData);
        }
    }

    private function decodeJsonToArray(string $value): array
    {
        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('JSON must decode to an array/object');
        }

        return $decoded;
    }
}
