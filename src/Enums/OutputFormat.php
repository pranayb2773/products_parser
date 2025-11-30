<?php

declare(strict_types=1);

namespace App\Enums;

enum OutputFormat: string
{
    case CSV = 'csv';
    case JSON = 'json';
    case XML = 'xml';

    public static function fromFilePath(string $filePath): self
    {
        $extension = mb_strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => self::JSON,
            'xml' => self::XML,
            default => self::CSV,
        };
    }
}
