<?php

declare(strict_types=1);

namespace App\Seeders;

use App\Contracts\SeederInterface;
use RuntimeException;

final readonly class JsonSeeder implements SeederInterface
{
    public function __construct(
        private ProductDataGenerator $generator,
    ) {
    }

    public function getExtension(): string
    {
        return 'json';
    }

    public function seed(string $outputPath, int $count): void
    {
        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$outputPath} for writing");
        }

        try {
            fwrite($handle, "[\n");

            for ($i = 0; $i < $count; $i++) {
                $product = $this->generator->generate($i);
                $json = json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                // Indent the JSON object
                $json = ' ' . str_replace("\n", "\n    ", $json);

                fwrite($handle, $json);

                // Add comma except for last item
                if ($i < $count - 1) {
                    fwrite($handle, ',');
                }

                fwrite($handle, "\n");
            }

            fwrite($handle, "]\n");
        } finally {
            fclose($handle);
        }
    }
}
