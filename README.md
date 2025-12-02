# Products Parser

Supplier product list processor in modern native PHP. Streams large CSV/TSV, JSON/NDJSON, and XML catalogs, normalizes each product, prints it, and counts unique combinations. A companion seeder generates synthetic datasets (CSV, TSV, JSON, NDJSON, XML) for testing/benchmarking. Optional PCNTL-powered parallel modes speed up both parsing and generation.

## Features
- Streaming parsers for CSV/TSV (delimiter auto-detect), JSON arrays/wrapped objects/NDJSON, and XML via XMLReader
- Field normalization that maps supplier headers (brand_name, model_name, gb_spec_name, etc.) to canonical fields (make, model, capacity, colour, network, grade, condition)
- Unique combination counter with CSV/JSON/XML export of aggregated counts
- Parallel processing and seeding via PCNTL for large inputs (sequential mode works everywhere)
- Seeder produces deterministic yet varied product rows for benchmarks or fixtures

## Requirements
- PHP 8.4+
- Extensions: ext-dom, ext-simplexml, ext-xmlreader; ext-pcntl required only when using --parallel
- Composer
- OS: any for sequential use; POSIX (Linux/macOS) required for PCNTL-based parallel modes (not available on stock Windows PHP)

## Installation
```bash
composer install --no-interaction --ignore-platform-reqs
```

## CLI: parser
```bash
php parser.php --file=<path> [--unique-combinations=<output>] [--parallel=<workers>]
```
- --file (required): input file; supports CSV, TSV, JSON, NDJSON, XML
- --unique-combinations: write aggregated counts; format inferred from extension (.csv, .json, .xml)
- --parallel: worker count (>=2 enables PCNTL parallel mode)
- --help, -h: show help

Examples:
```bash
php parser.php --file=data/input/products_comma_separated.csv --unique-combinations=data/output/combination_count.csv
php parser.php --file=data/input/products.json --unique-combinations=data/output/combination_count.json
php parser.php --file=data/input/products.xml --unique-combinations=data/output/combination_count.xml
php parser.php --file=data/input/products.csv --unique-combinations=data/output/results.json --parallel=4
```

## CLI: seeder
```bash
php seeder.php --type=<csv|tsv|json|ndjson|xml> --count=<number> [--output=<path>] [--parallel=<workers>]
```
- --type (required): output format
- --count (required): number of products to generate
- --output: custom output path (default: data/input/products.<type>)
- --parallel: worker count for faster generation (PCNTL)
- --help, -h: show help

Examples:
```bash
php seeder.php --type=csv --count=1000
php seeder.php --type=json --count=50000
php seeder.php --type=xml --count=100 --output=custom/data.xml
php seeder.php --type=ndjson --count=10000
php seeder.php --type=csv --count=1000000 --parallel=8
```

## Field mapping
Incoming headers are normalized to: make, model, colour, capacity, network, grade, condition.
Default aliases include: brand_name -> make, model_name -> model, colour_name -> colour, gb_spec_name -> capacity, network_name -> network, grade_name -> grade, condition_name -> condition.

## Data locations
- Inputs/examples: data/input/
- Suggested parser outputs: data/output/
- Seeder default target (when --output omitted): data/input/products.<type>

## Parallel mode notes
- Requires ext-pcntl and a POSIX PHP build; not available on standard Windows PHP.
- Parallel parsing partitions input then merges unique counts; parallel seeding merges worker outputs zero-copy per format.

## Testing and quality
```bash
composer test            # Pest suite
composer test:phpunit    # PHPUnit (TestDox)
composer test:parallel   # Pest in parallel
composer format          # php-cs-fixer
composer check-format    # format check (dry run)
```

## Architecture Documentation

### Overview
This application parses supplier product lists (CSV, TSV, JSON, NDJSON, XML), normalizes the fields, prints each product, and aggregates counts of unique product combinations. A seeder generates synthetic product data in all supported formats for benchmarks and fixtures, with optional parallel modes for both parsing and generation.

### Architecture Principles
- SOLID-friendly components with clear responsibilities
- Dependency injection via constructor arguments for factories, options, and writers
- Immutability where practical using readonly classes/properties
- Strict typing with `declare(strict_types=1)` across the codebase
- Memory efficiency through streaming/generator-based processing

### Directory Structure
```
.
|-- parser.php              # Parser CLI entrypoint
|-- seeder.php              # Seeder CLI entrypoint
|-- data/                   # Sample and generated input/output data
|   |-- input/
|   `-- output/
|-- src/
|   |-- Cli/                # CLI applications, options, output writers
|   |-- Contracts/          # Interfaces for parsers/seeders
|   |-- Enums/              # CSV delimiters/format, output formats
|   |-- Exceptions/         # Domain/runtime exceptions
|   |-- Mapping/            # Field mapper
|   |-- Models/             # Domain models (Product)
|   |-- Parsers/            # CSV/TSV, JSON/NDJSON, XML parsers + factory
|   |-- Seeders/            # Data generators and format-specific seeders
|   `-- Services/           # Unique counter, parallel processor/seeder
|-- tests/                  # Unit and integration tests (Pest/PHPUnit)
|-- composer.json           # Dependencies and scripts
|-- phpunit.xml             # Test suite configuration
`-- README.md               # Project documentation
```

### Core Components
- Models: `Product` is an immutable value object with validation and unique-key generation.
- Parsers: `ParserFactory` selects format-specific parsers; `CsvParser` (CSV/TSV with delimiter auto-detect), `JsonParser` (arrays, wrapped objects, NDJSON), `XmlParser` (streaming via XMLReader) all implement `FileParserInterface` and yield `Product` via generators.
- Mapping: `FieldMapper` normalizes varied supplier headers into canonical product fields; supports custom mappings.
- Services: `UniqueCounter` aggregates unique combinations and writes CSV/JSON/XML; `ParallelProcessor` partitions input and merges counts; `ParallelSeeder` coordinates multi-process seeding and zero-copy merges.
- CLI: `ParserApplication`/`SeederApplication` orchestrate options parsing, validation, and processing; `ParserOptions`/`SeederOptions` are simple value objects; `ParserOutputWriter`/`SeederOutputWriter` centralize console output and help text.
- Seeders: `ProductDataGenerator` yields deterministic-but-varied rows; format-specific seeders (CSV/TSV/JSON/NDJSON/XML) write files and are composed via `SeederFactory`.
- Enums and Contracts: Backed enums for CSV delimiters/format and output formats; interfaces define parser/seeder contracts for consistency.
- Exceptions: `RequiredFieldException` guards required product fields; runtime exceptions signal parsing/IO errors.

### Design Patterns
- Factory: `ParserFactory` and `SeederFactory` create format-specific implementations.
- Strategy: `FileParserInterface` and `SeederInterface` enable pluggable parsing/seeding strategies per format.
- Value Object: `Product` encapsulates normalized product data and uniqueness logic.
- Generator/Streaming: Parsers yield products lazily to minimize memory; JSON/NDJSON/XML are streamed.
- Dependency Injection: Constructors accept collaborators (field mapper, factories, writers) for testability.

### Data Flow
Parser path:
```
CLI (parser.php)
  -> ParserApplication
  -> ParserOptions (args)
  -> ParserFactory -> Parser (CSV/TSV/JSON/NDJSON/XML)
  -> Product stream (generator)
  -> UniqueCounter aggregates
  -> ParserOutputWriter prints stats/products
  -> UniqueCounter writes optional CSV/JSON/XML output
```

Seeder path:
```
CLI (seeder.php)
  -> SeederApplication
  -> SeederOptions (args)
  -> SeederFactory -> Format seeder (CSV/TSV/JSON/NDJSON/XML)
  -> ProductDataGenerator feeds rows
  -> Optional ParallelSeeder splits work and merges outputs
  -> SeederOutputWriter prints progress/success
```

### Performance Considerations
- Streaming parsers avoid loading full files; NDJSON and XML are iterated with buffered reads.
- CSV delimiter auto-detection and header normalization reduce pre-processing.
- UniqueCounter supports optional chunk flushing to temp files for extreme cardinality.
- Parallel modes use pcntl to partition work across processes and merge results efficiently (zero-copy merges for seeding outputs).

### Extensibility Points
1) New input formats: implement `FileParserInterface` and register in `ParserFactory`.
2) Custom header mappings: extend `FieldMapper` or inject custom mappings.
3) Additional output formats: extend `OutputFormat` and add writers in `UniqueCounter`.
4) New seeder formats: implement `SeederInterface` and register in `SeederFactory`.
5) Alternative output behavior: replace output writers to change console or file output formatting.

### Testing Strategy
- Pest test suite with unit coverage for models, mappers, options, and services.
- Integration tests for CSV/JSON/XML parsers and CLI flows.
- PHPUnit config available for compatibility (`composer test:phpunit`).

### PHP 8.4 Features Used
- `declare(strict_types=1)`
- readonly classes/properties where appropriate
- Enums for CSV/output formats
- Match expressions, named arguments, constructor property promotion for clarity

## License
MIT (see composer.json). Add a LICENSE file if you need the full text.

