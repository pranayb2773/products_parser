<?php

declare(strict_types=1);

namespace App\Enums;

enum CsvDelimiter: string
{
    case COMMA = ',';
    case TAB = "\t";
}
