<?php

declare(strict_types=1);

namespace App\Contracts;

use Generator;

interface FileParserInterface
{
    public function parse(string $filePath): Generator;
}
