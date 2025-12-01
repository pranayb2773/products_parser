<?php

declare(strict_types=1);

namespace App\Seeders;

use App\Contracts\SeederInterface;
use RuntimeException;

final readonly class XmlSeeder implements SeederInterface
{
    public function __construct(
        private ProductDataGenerator $generator,
    ) {
    }

    public function getExtension(): string
    {
        return 'xml';
    }

    public function seed(string $outputPath, int $count): void
    {
        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$outputPath} for writing");
        }

        try {
            fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<products>\n");

            for ($i = 0; $i < $count; $i++) {
                $product = $this->generator->generate($i);

                fwrite($handle, " <product>\n");

                foreach ($product as $tag => $value) {
                    $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

                    fwrite($handle, " <tag>{$escaped}</tag>\n");
                }

                fwrite($handle, " </product>\n");
            }

            fwrite($handle, "</products>\n");
        } finally {
            fclose($handle);
        }
    }
}
