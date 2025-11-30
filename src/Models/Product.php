<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\RequiredFieldException;
use JsonException;

final readonly class Product
{
    private function __construct(
        public string $make,
        public string $model,
        public ?string $colour = null,
        public ?string $capacity = null,
        public ?string $network = null,
        public ?string $grade = null,
        public ?string $condition = null
    ) {
        $this->validateRequiredFields();
    }

    /**
     * @throws JsonException
     */
    public function __toString(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    public static function create(
        string $make,
        string $model,
        ?string $colour = null,
        ?string $capacity = null,
        ?string $network = null,
        ?string $grade = null,
        ?string $condition = null,
    ): self {
        return new self(
            make: self::normalizeString($make),
            model: self::normalizeString($model),
            colour: self::normalizeOptionalString($colour),
            capacity: self::normalizeOptionalString($capacity),
            network: self::normalizeOptionalString($network),
            grade: self::normalizeOptionalString($grade),
            condition: self::normalizeOptionalString($condition),
        );
    }

    public static function fromArray(array $data): self
    {
        return self::create(
            make: (string) ($data['make'] ?? ''),
            model: (string) ($data['model'] ?? ''),
            colour: isset($data['colour']) ? (string) $data['colour'] : null,
            capacity: isset($data['capacity']) ? (string) $data['capacity'] : null,
            network: isset($data['network']) ? (string) $data['network'] : null,
            grade: isset($data['grade']) ? (string) $data['grade'] : null,
            condition: isset($data['condition']) ? (string) $data['condition'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'make' => $this->make,
            'model' => $this->model,
            'colour' => $this->colour,
            'capacity' => $this->capacity,
            'network' => $this->network,
            'grade' => $this->grade,
            'condition' => $this->condition,
        ];
    }

    public function getUniqueKey(): string
    {
        $payload = implode('|', [
            mb_strtolower($this->make),
            mb_strtolower($this->model),
            mb_strtolower($this->colour ?? ''),
            mb_strtolower($this->capacity ?? ''),
            mb_strtolower($this->network ?? ''),
            mb_strtolower($this->grade ?? ''),
            mb_strtolower($this->condition ?? ''),
        ]);

        return sha1($payload);
    }

    private static function normalizeString(string $value): string
    {
        return mb_trim($value);
    }

    private static function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_trim($value);
        return $normalized !== '' ? $normalized : null;
    }

    private function validateRequiredFields(): void
    {
        if ($this->make === '') {
            throw new RequiredFieldException("Required field 'make' is missing or empty");
        }

        if ($this->model === '') {
            throw new RequiredFieldException("Required field 'model' is missing or empty");
        }
    }
}
