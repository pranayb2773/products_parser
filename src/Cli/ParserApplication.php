<?php

declare(strict_types=1);

namespace App\Cli;

use App\Contracts\FileParserInterface;
use App\Mapping\FieldMapper;
use App\Parsers\ParserFactory;
use App\Services\ParallelProcessor;
use App\Services\UniqueCounter;
use JsonException;
use RuntimeException;

final readonly class ParserApplication
{
    private const int EXIT_SUCCESS = 0;
    private const int EXIT_ERROR = 1;

    public function __construct(
        private ParserFactory      $parserFactory = new ParserFactory(new FieldMapper()),
        private ParserOutputWriter $output = new ParserOutputWriter(),
    ) {
    }

    public function run(array $argv): int
    {
        try {
            if ($this->shouldShowHelp($argv)) {
                $this->output->writeHelp();
                return self::EXIT_SUCCESS;
            }

            $options = ParserOptions::fromArguments($argv);

            if (!$options->isValid()) {
                $this->output->writeError('--file parameter is required');
                $this->output->writeLine('');
                $this->output->writeHelp();
                return self::EXIT_ERROR;
            }

            return $this->processFile($options);
        } catch (RuntimeException $e) {
            $this->output->writeError($e->getMessage());
            return self::EXIT_ERROR;
        }
    }

    private function shouldShowHelp(array $args): bool
    {
        return array_any($args, fn ($arg) => $arg === '--help' || $arg === '-h');
    }

    /**
     * @throws JsonException
     */
    private function writeOutputFile(string $outputPath, UniqueCounter $uniqueCounter): void
    {
        $uniqueCounter->writeToFile($outputPath);
        $this->output->writeOutputConfirmation($outputPath);
    }

    /**
     * @throws JsonException
     */
    private function processFile(ParserOptions $options): int
    {
        $start = microtime(true);
        $inputFile = $options->inputFile;

        if (!file_exists($inputFile)) {
            throw new RuntimeException("File not found: {$inputFile}");
        }

        $parser = $this->parserFactory->createFromFile($inputFile);
        $this->output->writeFileHeader($inputFile);

        if ($options->isParallel()) {
            return $this->processFileParallel($options, $parser, $start);
        }

        return $this->processFileSequential($options, $parser, $start);
    }

    /**
     * @throws JsonException
     */
    private function processFileSequential(ParserOptions $options, FileParserInterface $parser, float $start): int
    {
        $uniqueCounter = new UniqueCounter();
        $productCount = 0;

        foreach ($parser->parse($options->inputFile) as $product) {
            $productCount++;
            $this->output->writeProduct($product);
            $uniqueCounter->addProduct($product);
        }

        $this->output->writeStatistics($productCount, $uniqueCounter->getCount());
        $this->output->writeTiming($start, microtime(true), $productCount);

        if ($options->hasOutputFile()) {
            $this->writeOutputFile($options->outputFile, $uniqueCounter);
        }

        return self::EXIT_SUCCESS;
    }

    /**
     * @throws JsonException
     */
    private function processFileParallel(ParserOptions $options, FileParserInterface $parser, float $start): int
    {
        $parallelProcessor = new ParallelProcessor($options->parallelWorkers, $this->output);
        $uniqueCounter = $parallelProcessor->process($parser, $options->inputFile);

        $productCount = array_sum(array_map(
            fn ($data) => $data['count'],
            $uniqueCounter->getCombinations()
        ));

        $this->output->writeStatistics($productCount, $uniqueCounter->getCount());
        $this->output->writeTiming($start, microtime(true), $productCount);

        if ($options->hasOutputFile()) {
            $this->writeOutputFile($options->outputFile, $uniqueCounter);
        }

        return self::EXIT_SUCCESS;
    }
}
