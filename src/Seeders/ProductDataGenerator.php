<?php

declare(strict_types=1);

namespace App\Seeders;

final class ProductDataGenerator
{
    private const array BRANDS = ['Apple', 'Samsung', 'Google', 'OnePlus', 'Xiaomi'];
    private const array MODELS = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'];
    private const array COLOURS = ['Red', 'Blue', 'Green', 'Black', 'White'];
    private const array CAPACITIES = ['64GB', '128GB', '256GB', '512GB'];
    private const array NETWORKS = ['Unlocked', 'EE', 'O2', 'Vodafone'];
    private const array GRADES = ['Grade A', 'Grade B', 'Grade C'];
    private const array CONDITIONS = ['Working', 'Faulty'];

    /**
     * Generate product data with random values
     */
    public function generate(int $index): array
    {
        return [
            'brand_name'   => self::BRANDS[array_rand(self::BRANDS)],
            'model_name'   => self::MODELS[array_rand(self::MODELS)] . ' ' . ($index % 100),
            'colour_name'  => self::COLOURS[array_rand(self::COLOURS)],
            'gb_spec_name' => self::CAPACITIES[array_rand(self::CAPACITIES)],
            'network_name' => self::NETWORKS[array_rand(self::NETWORKS)],
            'grade_name'   => self::GRADES[array_rand(self::GRADES)],
            'condition'    => self::CONDITIONS[array_rand(self::CONDITIONS)],
        ];
    }

    /**
     * Get the field names/headers for product data
     */
    public function getHeaders(): array
    {
        return [
            'brand_name',
            'model_name',
            'colour_name',
            'gb_spec_name',
            'network_name',
            'grade_name',
            'condition',
        ];
    }
}
