<?php

declare(strict_types=1);

use Database\Seeders\SupplierSeeder;

describe('Customer Booking Journey', function () {
    it('allows a customer to find an offer, book the last available unit, retry idempotently, and see it become sold out', function () {
        $this->seed(SupplierSeeder::class);

        $importResponse = $this->postJson('/api/imports', validImportPayload([
            'supplier' => 'supplier-a',
            'external_import_id' => 'customer-journey',
            'offers' => [
                validOfferPayload([
                    'external_id' => 'last-apartment',
                    'property' => [
                        'code' => 'BCN-JOURNEY',
                        'name' => 'Central apartment',
                        'city' => 'Barcelona',
                    ],
                    'available_units' => 1,
                    'price' => 50000,
                ]),
            ],
        ]));

        $importResponse->assertAccepted();
        $importId = (int) $importResponse->json('data.id');

        $this->getJson("/api/imports/{$importId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $searchQuery = http_build_query([
            'city' => 'Barcelona',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]);

        $searchResponse = $this->getJson("/api/properties?{$searchQuery}");
        $searchResponse->assertOk()->assertJsonCount(1, 'data');

        $offerId = (int) $searchResponse->json('data.0.best_offer.id');
        $reservationEndpoint = "/api/offers/{$offerId}/reservations";

        $customerOrder = validReservationPayload([
            'client_reference' => 'customer-order-1',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ]);

        $bookingResponse = $this->postJson($reservationEndpoint, $customerOrder);
        $bookingResponse->assertCreated();
        $bookingId = (int) $bookingResponse->json('data.id');

        $this->postJson($reservationEndpoint, $customerOrder)
            ->assertOk()
            ->assertJsonPath('data.id', $bookingId);

        $competingCustomerOrder = array_merge($customerOrder, [
            'client_reference' => 'competing-order-2',
        ]);

        $this->postJson($reservationEndpoint, $competingCustomerOrder)
            ->assertConflict()
            ->assertJsonPath('code', 'offer_unavailable');

        $this->getJson("/api/properties?{$searchQuery}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('reservations', 1);
    });
});
