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
        // Use modulo to create more common combinations
        // This simulates real-world inventory where certain products are more popular
        return [
            'brand_name'   => self::BRANDS[$index % count(self::BRANDS)],
            'model_name'   => self::MODELS[$index % count(self::MODELS)] . ' ' . ($index % 20),
            'colour_name'  => self::COLOURS[$index % count(self::COLOURS)],
            'gb_spec_name' => self::CAPACITIES[$index % count(self::CAPACITIES)],
            'network_name' => self::NETWORKS[$index % count(self::NETWORKS)],
            'grade_name'   => self::GRADES[$index % count(self::GRADES)],
            'condition'    => self::CONDITIONS[$index % count(self::CONDITIONS)],
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
