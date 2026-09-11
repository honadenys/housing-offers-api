<?php

declare(strict_types=1);

use App\Models\Offer;

describe('Creating Reservations', function () {
    it('creates a reservation and decrements available units on the offer', function () {
        $offer = Offer::factory()->create(['available_units' => 2]);
        $reservationPayload = validReservationPayload();

        $response = $this->postJson("/api/offers/{$offer->id}/reservations", $reservationPayload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.offer_id', $offer->id)
            ->assertJsonPath('data.client_reference', $reservationPayload['client_reference']);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'available_units' => 1,
        ]);
    });

    it('validates that client reference, customer name, and customer email are required', function () {
        $offer = Offer::factory()->create(['available_units' => 1]);

        $response = $this->postJson("/api/offers/{$offer->id}/reservations", []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'client_reference',
                'customer_name',
                'customer_email',
            ]);
    });
});
