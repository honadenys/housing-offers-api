<?php

declare(strict_types=1);

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;

test('reservation is created and availability is decremented', function () {
    $offer = reservationOffer(['available_units' => 2]);

    $response = $this->postJson("/api/offers/{$offer->id}/reservations", reservationPayload());

    $response
        ->assertCreated()
        ->assertJsonPath('data.offer_id', $offer->id)
        ->assertJsonPath('data.client_reference', 'web-order-9f782b1c');
    $this->assertDatabaseCount('reservations', 1);
    $this->assertDatabaseHas('offers', [
        'id' => $offer->id,
        'available_units' => 1,
    ]);
});

test('expired offer cannot be reserved', function () {
    $offer = reservationOffer([
        'available_units' => 1,
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->postJson("/api/offers/{$offer->id}/reservations", reservationPayload());

    $response
        ->assertStatus(409)
        ->assertJsonPath('code', 'offer_unavailable');

    $this->assertDatabaseCount('reservations', 0);
});

test('empty offer cannot be reserved', function () {
    $offer = reservationOffer(['available_units' => 0]);

    $response = $this->postJson("/api/offers/{$offer->id}/reservations", reservationPayload());

    $response
        ->assertStatus(409)
        ->assertJsonPath('code', 'offer_unavailable');

    $this->assertDatabaseCount('reservations', 0);
});

test('repeating same reservation is idempotent', function () {
    $offer = reservationOffer(['available_units' => 1]);
    $payload = reservationPayload();

    $first = $this->postJson("/api/offers/{$offer->id}/reservations", $payload);
    $second = $this->postJson("/api/offers/{$offer->id}/reservations", $payload);

    $first->assertCreated();
    $second->assertOk();
    $this->assertSame($first->json('data.id'), $second->json('data.id'));
    $this->assertDatabaseCount('reservations', 1);
    $this->assertDatabaseHas('offers', [
        'id' => $offer->id,
        'available_units' => 0,
    ]);
});

test('reusing reference with different request is conflict', function () {
    $offer = reservationOffer(['available_units' => 2]);
    $payload = reservationPayload();

    $first = $this->postJson("/api/offers/{$offer->id}/reservations", $payload);
    $second = $this->postJson("/api/offers/{$offer->id}/reservations", [
        ...$payload,
        'customer_name' => 'Different Customer',
    ]);

    $first->assertCreated();
    $second
        ->assertStatus(409)
        ->assertJsonPath('code', 'reservation_reference_conflict');

    $this->assertDatabaseCount('reservations', 1);
    $this->assertDatabaseHas('offers', [
        'id' => $offer->id,
        'available_units' => 1,
    ]);
});

test('reservation payload is validated', function () {
    $offer = reservationOffer();

    $response = $this->postJson("/api/offers/{$offer->id}/reservations", []);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'client_reference',
            'customer_name',
            'customer_email',
        ]);
});

test('reference cannot be reused for another offer', function () {
    $firstOffer = Offer::factory()->create();
    $otherOffer = Offer::factory()->create();
    $payload = reservationPayload();

    $first = $this->postJson("/api/offers/{$firstOffer->id}/reservations", $payload);
    $second = $this->postJson("/api/offers/{$otherOffer->id}/reservations", $payload);

    $first->assertCreated();
    $second->assertConflict();
    $this->assertDatabaseCount('reservations', 1);
    $this->assertSame(2, $otherOffer->fresh()->available_units);
    $this->assertSame($firstOffer->supplier_id, $firstOffer->import->supplier_id);
});

function reservationOffer(array $overrides = []): Offer
{
    $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    $property = Property::factory()->create(['city' => 'Barcelona']);
    $import = Import::factory()->create(['supplier_id' => $supplier->id]);

    return Offer::query()->create(array_merge([
        'supplier_id' => $supplier->id,
        'import_id' => $import->id,
        'property_id' => $property->id,
        'external_id' => 'offer-'.uniqid(),
        'check_in' => '2026-10-10',
        'check_out' => '2026-10-15',
        'max_guests' => 4,
        'price' => 72500,
        'currency' => 'EUR',
        'available_units' => 2,
        'expires_at' => now()->addDay(),
    ], $overrides));
}

function reservationPayload(): array
{
    return [
        'client_reference' => 'web-order-9f782b1c',
        'customer_name' => 'John Smith',
        'customer_email' => 'john@example.com',
    ];
}
