<?php

declare(strict_types=1);

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;

describe('Property Search Filters', function () {
    it('excludes offers with mismatched dates, insufficient capacity, wrong city, zero units, or expired status', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $validProperty = Property::factory()->create([
            'city' => 'Barcelona',
            'code' => 'VALID',
        ]);
        $otherCityProperty = Property::factory()->create([
            'city' => 'Madrid',
            'code' => 'OTHER',
        ]);

        $validOffer = Offer::factory()->for($supplier)->for($validProperty)->create([
            'price' => 70000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 2,
            'available_units' => 2,
        ]);

        Offer::factory()->for($supplier)->for($validProperty)->soldOut()->create([
            'price' => 1000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        Offer::factory()->for($supplier)->for($validProperty)->create([
            'price' => 2000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 1,
        ]);

        Offer::factory()->for($supplier)->for($validProperty)->expired()->create([
            'price' => 3000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        Offer::factory()->for($supplier)->for($validProperty)->create([
            'price' => 4000,
            'check_in' => '2026-11-10',
            'check_out' => '2026-11-15',
        ]);

        Offer::factory()->for($supplier)->for($otherCityProperty)->create([
            'price' => 5000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        $response = $this->getJson('/api/properties?'.http_build_query([
            'city' => 'Barcelona',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'VALID')
            ->assertJsonPath('data.0.best_offer.id', $validOffer->id)
            ->assertJsonPath('data.0.best_offer.price', 70000);
    });

    it('supports filtering by numeric city names like "0"', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $numericCityProperty = Property::factory()->create(['city' => '0']);
        $barcelonaProperty = Property::factory()->create(['city' => 'Barcelona']);

        Offer::factory()->for($supplier)->for($numericCityProperty)->create([
            'price' => 50000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);
        Offer::factory()->for($supplier)->for($barcelonaProperty)->create([
            'price' => 60000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        $response = $this->getJson('/api/properties?'.http_build_query([
            'city' => '0',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city', '0');
    });

    it('searches across all cities when the city parameter is omitted', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $cityZeroProperty = Property::factory()->create(['city' => '0']);
        $barcelonaProperty = Property::factory()->create(['city' => 'Barcelona']);

        Offer::factory()->for($supplier)->for($cityZeroProperty)->create([
            'price' => 50000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);
        Offer::factory()->for($supplier)->for($barcelonaProperty)->create([
            'price' => 60000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        $response = $this->getJson('/api/properties?'.http_build_query([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters offers by currency when specified', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $property = Property::factory()->create(['city' => 'Barcelona']);

        Offer::factory()->for($supplier)->for($property)->create([
            'price' => 1000,
            'currency' => 'USD',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);
        $eurOffer = Offer::factory()->for($supplier)->for($property)->create([
            'price' => 2000,
            'currency' => 'EUR',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        $eurResponse = $this->getJson('/api/properties?'.http_build_query([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
            'currency' => 'EUR',
        ]));

        $eurResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $eurOffer->id);

        $gbpResponse = $this->getJson('/api/properties?'.http_build_query([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
            'currency' => 'GBP',
        ]));

        $gbpResponse
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});
