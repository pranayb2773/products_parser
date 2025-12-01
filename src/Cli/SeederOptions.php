<?php

declare(strict_types=1);

namespace App\Cli;

final readonly class SeederOptions
{
    public function __construct(
        public ?string $type = null,
        public ?int $count = null,
        public ?string $outputFile = null,
    ) {
    }

    public static function fromArguments(array $argv): self
    {
        $type = null;
        $count = null;
        $outputFile = null;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--type=')) {
                $type = mb_substr($arg, 7);
            } elseif (str_starts_with($arg, '--count=')) {
                $count = (int) mb_substr($arg, 8);
            } elseif (str_starts_with($arg, '--output=')) {
                $outputFile = mb_substr($arg, 9);
            }
        }

        return new self(
            type: $type,
            count: $count,
            outputFile: $outputFile,
        );
    }

    public function isValid(): bool
    {
        return $this->type !== null
            && $this->count !== null
            && $this->count > 0;
    }

    public function hasOutputFile(): bool
    {
        return $this->outputFile !== null;
    }

    public function getOutputFile(string $defaultExtension): string
    {
        if ($this->hasOutputFile()) {
            return $this->outputFile;
        }

        return dirname(__DIR__, 2) . "/data/input/products.{$defaultExtension}";
    }
}
