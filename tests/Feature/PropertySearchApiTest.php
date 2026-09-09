<?php

declare(strict_types=1);

use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;

test('search returns cheapest matching offer per property', function () {
    $supplierA = Supplier::factory()->create(['code' => 'supplier-a']);
    $supplierB = Supplier::factory()->create(['code' => 'supplier-b']);
    $property = Property::factory()->create([
        'code' => 'BCN-0001',
        'name' => 'Sagrada Familia Apartment',
        'city' => 'Barcelona',
    ]);

    $expensive = propertySearchOffer($supplierA, $property, 72500);
    $cheap = propertySearchOffer($supplierB, $property, 65000);

    $response = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2');

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
        ->assertJsonPath('data.0.best_offer.id', $cheap->id)
        ->assertJsonPath('data.0.best_offer.supplier', 'supplier-b')
        ->assertJsonPath('data.0.best_offer.price', 65000);

    $this->assertNotSame($expensive->id, $response->json('data.0.best_offer.id'));
});

test('search excludes wrong date guests city units and expired offers', function () {
    $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    $validProperty = Property::factory()->create(['code' => 'VALID', 'city' => 'Barcelona']);
    $otherProperty = Property::factory()->create(['code' => 'OTHER', 'city' => 'Madrid']);

    propertySearchOffer($supplier, $validProperty, 70000);
    propertySearchOffer($supplier, $validProperty, 1000, ['available_units' => 0]);
    propertySearchOffer($supplier, $validProperty, 2000, ['max_guests' => 1]);
    propertySearchOffer($supplier, $validProperty, 3000, ['expires_at' => now()->subMinute()]);
    propertySearchOffer($supplier, $validProperty, 4000, ['check_in' => '2026-11-10']);
    propertySearchOffer($supplier, $otherProperty, 5000);

    $response = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2');

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'VALID')
        ->assertJsonPath('data.0.best_offer.price', 70000);
});

test('equal prices use offer id as deterministic tie breaker', function () {
    $supplierA = Supplier::factory()->create(['code' => 'supplier-a']);
    $supplierB = Supplier::factory()->create(['code' => 'supplier-b']);
    $property = Property::factory()->create(['code' => 'TIE', 'city' => 'Barcelona']);

    $first = propertySearchOffer($supplierA, $property, 50000);
    propertySearchOffer($supplierB, $property, 50000);

    $response = $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2');

    $response->assertJsonPath('data.0.best_offer.id', $first->id);
});

test('search is paginated and preserves filters in next and previous links', function () {
    $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

    foreach (['P-1', 'P-2', 'P-3'] as $index => $code) {
        $property = Property::factory()->create(['code' => $code, 'city' => 'Barcelona']);
        propertySearchOffer($supplier, $property, 52000 - ($index * 1000));
    }

    $query = 'city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&per_page=2';
    $firstPage = $this->getJson("/api/properties?{$query}&page=1");
    $secondPage = $this->getJson("/api/properties?{$query}&page=2");

    $firstPage
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('per_page', 2)
        ->assertJsonPath('prev', null)
        ->assertJsonPath('data.0.code', 'P-3')
        ->assertJsonPath('data.1.code', 'P-2');
    $secondPage
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'P-1')
        ->assertJsonPath('per_page', 2)
        ->assertJsonPath('next', null);

    $this->assertNotNull($firstPage->json('next'));
    $this->assertNotNull($secondPage->json('prev'));
    $this->assertStringContainsString('check_in=2026-10-10', $firstPage->json('next'));
});

test('invalid search dates are rejected', function () {
    $query = 'check_in=2026-10-15&check_out=2026-10-10&guests=2';

    $response = $this->getJson("/api/properties?{$query}");

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_out']);
});

test('array dates are rejected', function () {
    $query = http_build_query(['check_in' => ['2026-10-10'], 'check_out' => '2026-10-15', 'guests' => 2]);

    $response = $this->getJson('/api/properties?'.$query);

    $response->assertUnprocessable()->assertJsonValidationErrors('check_in');
});

function propertySearchOffer(
    Supplier $supplier,
    Property $property,
    int $price,
    array $overrides = [],
): Offer {
    $import = Import::factory()->create(['supplier_id' => $supplier->id]);

    return Offer::query()->create(array_merge([
        'supplier_id' => $supplier->id,
        'import_id' => $import->id,
        'property_id' => $property->id,
        'external_id' => "offer-{$property->code}-{$price}-".uniqid(),
        'check_in' => '2026-10-10',
        'check_out' => '2026-10-15',
        'max_guests' => 4,
        'price' => $price,
        'currency' => 'EUR',
        'available_units' => 2,
        'expires_at' => now()->addDay(),
    ], $overrides));
}
