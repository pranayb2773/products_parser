<?php

declare(strict_types=1);

namespace App\Enums;

enum CsvFormat: string
{
    case ENCLOSURE = '"';
    case ESCAPE = '';
}
