<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Contracts\FileParserInterface;
use App\Mapping\FieldMapper;
use App\Models\Product;
use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

final readonly class XmlParser implements FileParserInterface
{
    public function __construct(
        private FieldMapper $fieldMapper = new FieldMapper(),
    ) {
    }

    public function parse(string $filePath): Generator
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new RuntimeException("File is not readable: {$filePath}");
        }

        libxml_use_internal_errors(true);

        $reader = new XMLReader();

        if (!$reader->open($filePath)) {
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            throw new RuntimeException("Failed to open XML file: {$filePath}");
        }

        $foundProduct = false;
        $index = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if (!$this->isProductElement($reader->name)) {
                    continue;
                }

                $outerXml = $reader->readOuterXml();

                if ($outerXml === false) {
                    throw new RuntimeException('Failed to read XML element');
                }

                $productElement = new SimpleXMLElement($outerXml);

                try {
                    $productData = $this->xmlElementToArray($productElement);
                    $mappedData = $this->fieldMapper->mapRow(
                        array_keys($productData),
                        array_values($productData)
                    );
                    yield Product::fromArray($mappedData);
                    $foundProduct = true;
                    $index++;
                } catch (RuntimeException $e) {
                    throw new RuntimeException(
                        "Error processing product at index {$index}: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            $this->throwIfXmlErrors();

            if (!$foundProduct) {
                throw new RuntimeException('No product elements found in XML');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors(false);
        }
    }

    private function isProductElement(string $name): bool
    {
        return $name === 'product' || $name === 'item';
    }

    private function xmlElementToArray(SimpleXMLElement $element): array
    {
        $result = [];

        // Get attributes
        foreach ($element->attributes() as $name => $value) {
            $result[(string) $name] = (string) $value;
        }

        // Get child elements
        foreach ($element->children() as $name => $child) {
            // If child has children or attributes, convert recursively
            if ($child->count() > 0 || $child->attributes()->count() > 0) {
                $childArray = $this->xmlElementToArray($child);
                $result[(string) $name] = json_encode($childArray);
            } else {
                $result[(string) $name] = (string) $child;
            }
        }

        // If no attributes and no children, use the text content
        if (empty($result)) {
            $result['value'] = (string) $element;
        }

        return $result;
    }

    private function throwIfXmlErrors(): void
    {
        $errors = libxml_get_errors();

        if (!empty($errors)) {
            $messages = array_map(
                static fn ($error) => mb_trim($error->message),
                $errors
            );
            throw new RuntimeException('Failed to parse XML: ' . implode('; ', $messages));
        }
    }
}
