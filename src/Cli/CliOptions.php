<?php

declare(strict_types=1);

namespace App\Cli;

final readonly class CliOptions
{
    public function __construct(
        public ?string $inputFile = null,
        public ?string $outputFile = null
    ) {
    }

    public static function fromArguments(array $args): self
    {
        $inputFile = null;
        $outputFile = null;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--file=')) {
                $inputFile = mb_substr($arg, 7);
            } elseif (str_starts_with($arg, '--unique-combinations=')) {
                $outputFile = mb_substr($arg, 22);
            }
        }

        return new self($inputFile, $outputFile);
    }

    public function isValid(): bool
    {
        return $this->inputFile !== null;
    }

    public function hasOutputFile(): bool
    {
        return $this->outputFile !== null;
    }
}
