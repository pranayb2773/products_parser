<?php

declare(strict_types=1);

namespace App\Contracts;

interface SeederInterface
{
    public function seed(string $outputPath, int $count): void;

    public function getExtension();
}
