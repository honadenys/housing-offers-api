<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'PROP-'.$this->faker->unique()->numerify('####'),
            'name' => $this->faker->streetName().' Apartment',
            'city' => $this->faker->city(),
        ];
    }
}
