<?php

declare(strict_types=1);

namespace App\Seeders;

use App\Contracts\SeederInterface;
use RuntimeException;

final readonly class NdjsonSeeder implements SeederInterface
{
    public function __construct(
        private ProductDataGenerator $generator,
    ) {
    }

    public function getExtension(): string
    {
        return 'ndjson';
    }

    public function seed(string $outputPath, int $count): void
    {
        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$outputPath} for writing");
        }

        try {
            for ($i = 0; $i < $count; $i++) {
                $productData = $this->generator->generate($i);

                fwrite($handle, json_encode($productData, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            }
        } finally {
            fclose($handle);
        }
    }
}
