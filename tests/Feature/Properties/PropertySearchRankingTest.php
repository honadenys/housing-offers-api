<?php

declare(strict_types=1);

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;

describe('Property Search Ranking & Best Offer Selection', function () {
    it('returns the cheapest matching offer per property when multiple suppliers offer it', function () {
        $supplierA = Supplier::factory()->create(['code' => 'supplier-a']);
        $supplierB = Supplier::factory()->create(['code' => 'supplier-b']);
        $property = Property::factory()->create([
            'code' => 'BCN-0001',
            'name' => 'Sagrada Familia Apartment',
            'city' => 'Barcelona',
        ]);

        $expensiveOffer = Offer::factory()
            ->for($supplierA)
            ->for($property)
            ->create([
                'price' => 72500,
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 2,
                'available_units' => 2,
            ]);

        $cheapOffer = Offer::factory()
            ->for($supplierB)
            ->for($property)
            ->create([
                'price' => 65000,
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 2,
                'available_units' => 2,
            ]);

        $response = $this->getJson('/api/properties?'.http_build_query([
            'city' => 'Barcelona',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]));

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['code', 'name', 'city', 'best_offer']],
                'next',
                'prev',
                'per_page',
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BCN-0001')
            ->assertJsonPath('data.0.best_offer.id', $cheapOffer->id)
            ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b')
            ->assertJsonPath('data.0.best_offer.price', 65000);

        expect($response->json('data.0.best_offer.id'))->not->toBe($expensiveOffer->id);
    });

    it('uses offer ID as a deterministic tie-breaker when two offers have the exact same price', function () {
        $supplierA = Supplier::factory()->create(['code' => 'supplier-a']);
        $supplierB = Supplier::factory()->create(['code' => 'supplier-b']);
        $property = Property::factory()->create([
            'code' => 'TIE',
            'city' => 'Barcelona',
        ]);

        $firstOffer = Offer::factory()->for($supplierA)->for($property)->create([
            'price' => 50000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);
        Offer::factory()->for($supplierB)->for($property)->create([
            'price' => 50000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        $response = $this->getJson('/api/properties?'.http_build_query([
            'city' => 'Barcelona',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]));

        $response->assertJsonPath('data.0.best_offer.id', $firstOffer->id);
    });
});
