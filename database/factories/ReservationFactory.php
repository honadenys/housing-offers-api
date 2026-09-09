<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'client_reference' => 'client-'.$this->faker->unique()->numerify('########'),
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
        ];
    }
}
