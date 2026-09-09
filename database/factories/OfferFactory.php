<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        $checkIn = now()->addDays(30)->startOfDay();

        return [
            'import_id' => Import::factory(),
            'supplier_id' => fn (array $attributes): int => Import::findOrFail((int) $attributes['import_id'])->supplier_id,
            'property_id' => Property::factory(),
            'external_id' => 'offer-'.$this->faker->unique()->numerify('########'),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(5)->toDateString(),
            'max_guests' => 2,
            'price' => $this->faker->numberBetween(10000, 200000),
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => now()->addDay(),
        ];
    }
}
