<?php

declare(strict_types=1);

use App\Cli\ParserApplication;

require_once 'vendor/autoload.php';

$application = new ParserApplication();
exit($application->run($argv));
