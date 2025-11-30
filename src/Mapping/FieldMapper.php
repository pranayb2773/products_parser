<?php

declare(strict_types=1);

namespace App\Mapping;

final class FieldMapper
{
    private array $mappings;

    public function __construct(array $customMappings = [])
    {
        $this->mappings = [...$this->getDefaultMappings(), ...$customMappings];
    }

    public function mapRow(array $headers, array $row): array
    {
        $mappedData = [];

        foreach ($headers as $index => $header) {
            $normalizedHeader = $this->normalizeHeader($header);

            if (isset($this->mappings[$normalizedHeader])) {
                $targetField = $this->mappings[$normalizedHeader];
                $mappedData[$targetField] = $row[$index] ?? null;
            }
        }

        return $mappedData;
    }

    public function addMapping(string $sourceField, string $targetField): void
    {
        $this->mappings[$this->normalizeHeader($sourceField)] = $targetField;
    }

    private function getDefaultMappings(): array
    {
        return [
            'brand_name' => 'make',
            'model_name' => 'model',
            'colour_name' => 'colour',
            'gb_spec_name' => 'capacity',
            'network_name' => 'network',
            'grade_name' => 'grade',
            'condition_name' => 'condition',
            // Direct mappings (in case headers already match)
            'make' => 'make',
            'model' => 'model',
            'colour' => 'colour',
            'capacity' => 'capacity',
            'network' => 'network',
            'grade' => 'grade',
            'condition' => 'condition',
        ];
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower(mb_trim($header));
    }
}
