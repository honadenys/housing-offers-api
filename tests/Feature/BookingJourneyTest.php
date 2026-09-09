<?php

declare(strict_types=1);

use Database\Seeders\SupplierSeeder;

test('customer finds an imported offer and books its final unit', function () {
    $this->seed(SupplierSeeder::class);
    $payload = [
        'supplier' => 'supplier-a',
        'external_import_id' => 'customer-journey',
        'sent_at' => now()->toIso8601String(),
        'offers' => [[
            'external_id' => 'last-apartment',
            'property' => ['code' => 'BCN-JOURNEY', 'name' => 'Central apartment', 'city' => 'Barcelona'],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 2,
            'price' => 50000,
            'currency' => 'EUR',
            'available_units' => 1,
            'expires_at' => now()->addDay()->toIso8601String(),
        ]],
    ];

    $import = $this->postJson('/api/imports', $payload)->assertAccepted();
    $this->getJson('/api/imports/'.$import->json('data.id'))
        ->assertOk()->assertJsonPath('data.status', 'completed');

    $url = '/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2';
    $search = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    $offerId = $search->json('data.0.best_offer.id');
    $reservationUrl = "/api/offers/{$offerId}/reservations";
    $customer = [
        'client_reference' => 'customer-order',
        'customer_name' => 'John Smith',
        'customer_email' => 'john@example.com',
    ];

    $booking = $this->postJson($reservationUrl, $customer)->assertCreated();
    $this->postJson($reservationUrl, $customer)
        ->assertOk()->assertJsonPath('data.id', $booking->json('data.id'));
    $this->postJson($reservationUrl, [...$customer, 'client_reference' => 'another-order'])
        ->assertConflict()->assertJsonPath('code', 'offer_unavailable');
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->assertDatabaseCount('reservations', 1);
});
