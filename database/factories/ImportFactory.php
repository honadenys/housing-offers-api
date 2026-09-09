<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => 'import-'.$this->faker->unique()->numerify('########'),
            'sent_at' => now()->subMinute(),
            'payload' => ['offers' => []],
            'status' => ImportStatus::Pending,
            'total_offers' => 0,
            'processed_offers' => 0,
            'error' => null,
            'completed_at' => null,
        ];
    }
}
