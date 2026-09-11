<?php

declare(strict_types=1);

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;

describe('Property Search Pagination', function () {
    it('paginates results across pages and retains search filter query parameters in links', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

        $cheapestProperty = Property::factory()->create(['city' => 'Barcelona', 'code' => 'P-CHEAP']);
        $midProperty = Property::factory()->create(['city' => 'Barcelona', 'code' => 'P-MID']);
        $expensiveProperty = Property::factory()->create(['city' => 'Barcelona', 'code' => 'P-EXPENSIVE']);

        Offer::factory()->for($supplier)->for($cheapestProperty)->create([
            'price' => 50000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);
        Offer::factory()->for($supplier)->for($midProperty)->create([
            'price' => 51000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);
        Offer::factory()->for($supplier)->for($expensiveProperty)->create([
            'price' => 52000,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
        ]);

        $baseFilters = [
            'city' => 'Barcelona',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
            'per_page' => 2,
        ];

        $firstPage = $this->getJson('/api/properties?'.http_build_query(array_merge($baseFilters, ['page' => 1])));

        $firstPage
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('prev', null)
            ->assertJsonPath('data.0.code', 'P-CHEAP')
            ->assertJsonPath('data.1.code', 'P-MID');

        expect($firstPage->json('next'))->not->toBeNull();
        expect($firstPage->json('next'))->toContain('check_in=2026-10-10');

        $secondPage = $this->getJson('/api/properties?'.http_build_query(array_merge($baseFilters, ['page' => 2])));

        $secondPage
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'P-EXPENSIVE')
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('next', null);

        expect($secondPage->json('prev'))->not->toBeNull();
    });
});
