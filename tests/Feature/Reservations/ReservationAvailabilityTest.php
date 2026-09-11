<?php

declare(strict_types=1);

use App\Models\Offer;

describe('Reservation Availability & Expiration', function () {
    it('rejects reservations when an offer is completely sold out', function () {
        $soldOutOffer = Offer::factory()->soldOut()->create();

        $response = $this->postJson("/api/offers/{$soldOutOffer->id}/reservations", validReservationPayload());

        $response
            ->assertStatus(409)
            ->assertJsonPath('code', 'offer_unavailable');

        $this->assertDatabaseCount('reservations', 0);
    });

    it('rejects reservations when an offer has expired', function () {
        $expiredOffer = Offer::factory()->expired()->create();

        $response = $this->postJson("/api/offers/{$expiredOffer->id}/reservations", validReservationPayload());

        $response
            ->assertStatus(409)
            ->assertJsonPath('code', 'offer_unavailable');

        $this->assertDatabaseCount('reservations', 0);
    });
});
