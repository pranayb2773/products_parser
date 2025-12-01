<?php

declare(strict_types=1);

use App\Cli\SeederApplication;

require_once 'vendor/autoload.php';

$application = new SeederApplication();
exit($application->run($argv));
