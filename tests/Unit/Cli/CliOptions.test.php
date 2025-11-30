<?php

declare(strict_types=1);

use App\Cli\CliOptions;

test('from arguments with both options', function () {
    $argv = [
        'parser.php',
        '--file=test.csv',
        '--unique-combinations=output.csv',
    ];

    $options = CliOptions::fromArguments($argv);

    expect($options->inputFile)->toBe('test.csv')
        ->and($options->outputFile)->toBe('output.csv')
        ->and($options->isValid())->toBeTrue()
        ->and($options->hasOutputFile())->toBeTrue();
});

test('from arguments with only file', function () {
    $argv = [
        'parser.php',
        '--file=test.json',
    ];

    $options = CliOptions::fromArguments($argv);

    expect($options->inputFile)->toBe('test.json')
        ->and($options->outputFile)->toBeNull()
        ->and($options->isValid())->toBeTrue()
        ->and($options->hasOutputFile())->toBeFalse();
});

test('from arguments with no options', function () {
    $argv = ['parser.php'];

    $options = CliOptions::fromArguments($argv);

    expect($options->inputFile)->toBeNull()
        ->and($options->outputFile)->toBeNull()
        ->and($options->isValid())->toBeFalse()
        ->and($options->hasOutputFile())->toBeFalse();
});

test('from arguments ignores help flag', function () {
    $argv = [
        'parser.php',
        '--file=test.xml',
        '--help',
    ];

    $options = CliOptions::fromArguments($argv);

    expect($options->inputFile)->toBe('test.xml')
        ->and($options->isValid())->toBeTrue();
});

test('from arguments with file path containing equals', function () {
    $argv = [
        'parser.php',
        '--file=path/to/file=with=equals.csv',
    ];

    $options = CliOptions::fromArguments($argv);

    expect($options->inputFile)->toBe('path/to/file=with=equals.csv');
});
