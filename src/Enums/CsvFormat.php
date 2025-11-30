<?php

namespace App\Enums;

enum CsvFormat: string
{
    case ENCLOSURE = '"';
    case ESCAPE = '';
}
