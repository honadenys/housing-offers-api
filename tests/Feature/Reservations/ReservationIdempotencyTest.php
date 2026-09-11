<?php

declare(strict_types=1);

use App\Models\Offer;

describe('Reservation Idempotency & Reference Conflict Prevention', function () {
    it('returns the existing reservation when repeating the exact same request', function () {
        $offer = Offer::factory()->create(['available_units' => 1]);
        $reservationPayload = validReservationPayload();

        $firstAttempt = $this->postJson("/api/offers/{$offer->id}/reservations", $reservationPayload);
        $secondAttempt = $this->postJson("/api/offers/{$offer->id}/reservations", $reservationPayload);

        $firstAttempt->assertCreated();
        $secondAttempt->assertOk();

        expect($secondAttempt->json('data.id'))->toBe($firstAttempt->json('data.id'));

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'available_units' => 0,
        ]);
    });

    it('returns a conflict error if the same client reference is reused with different customer data', function () {
        $offer = Offer::factory()->create(['available_units' => 2]);
        $sharedReference = 'order-ref-12345';

        $firstAttempt = $this->postJson("/api/offers/{$offer->id}/reservations", validReservationPayload([
            'client_reference' => $sharedReference,
            'customer_name' => 'Original Customer',
        ]));
        $firstAttempt->assertCreated();

        $conflictingAttempt = $this->postJson("/api/offers/{$offer->id}/reservations", validReservationPayload([
            'client_reference' => $sharedReference,
            'customer_name' => 'Different Customer Name',
        ]));

        $conflictingAttempt
            ->assertStatus(409)
            ->assertJsonPath('code', 'reservation_reference_conflict');

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'available_units' => 1,
        ]);
    });

    it('returns a conflict error if the same client reference is reused for a completely different offer', function () {
        $firstOffer = Offer::factory()->create();
        $secondOffer = Offer::factory()->create();
        $sharedReferencePayload = validReservationPayload();

        $firstAttempt = $this->postJson("/api/offers/{$firstOffer->id}/reservations", $sharedReferencePayload);
        $firstAttempt->assertCreated();

        $conflictingAttempt = $this->postJson("/api/offers/{$secondOffer->id}/reservations", $sharedReferencePayload);
        $conflictingAttempt->assertConflict();

        $this->assertDatabaseCount('reservations', 1);
        expect($secondOffer->fresh()->available_units)->toBe(2);
        expect($firstOffer->supplier_id)->toBe($firstOffer->import->supplier_id);
    });
});
