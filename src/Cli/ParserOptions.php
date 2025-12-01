<?php

declare(strict_types=1);

namespace App\Cli;

final readonly class ParserOptions
{
    public function __construct(
        public ?string $inputFile = null,
        public ?string $outputFile = null,
        public int $parallelWorkers = 1
    ) {
    }

    public static function fromArguments(array $args): self
    {
        $inputFile = null;
        $outputFile = null;
        $parallelWorkers = 1;

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--file=')) {
                $inputFile = mb_substr($arg, 7);
            } elseif (str_starts_with($arg, '--unique-combinations=')) {
                $outputFile = mb_substr($arg, 22);
            } elseif (str_starts_with($arg, '--parallel=')) {
                $parallelWorkers = max(1, (int) mb_substr($arg, 11));
            }
        }

        return new self($inputFile, $outputFile, $parallelWorkers);
    }

    public function isValid(): bool
    {
        return $this->inputFile !== null;
    }

    public function hasOutputFile(): bool
    {
        return $this->outputFile !== null;
    }

    public function isParallel(): bool
    {
        return $this->parallelWorkers > 1;
    }
}
