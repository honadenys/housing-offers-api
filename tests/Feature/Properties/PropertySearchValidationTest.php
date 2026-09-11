<?php

declare(strict_types=1);

describe('Property Search Validation Rules', function () {
    it('rejects searches where checkout date is before check-in date', function () {
        $response = $this->getJson('/api/properties?'.http_build_query([
            'check_in' => '2026-10-15',
            'check_out' => '2026-10-10',
            'guests' => 2,
        ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_out']);
    });

    it('rejects date inputs passed as arrays', function () {
        $response = $this->getJson('/api/properties?'.http_build_query([
            'check_in' => ['2026-10-10'],
            'check_out' => '2026-10-15',
            'guests' => 2,
        ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_in');
    });

    it('rejects check-in dates in the past and guest counts exceeding 30', function () {
        $response = $this->getJson('/api/properties?'.http_build_query([
            'check_in' => '2026-09-08',
            'check_out' => '2026-10-15',
            'guests' => 31,
        ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_in', 'guests']);
    });

    it('accepts valid boundary values such as today for check-in and exactly 30 guests', function () {
        $response = $this->getJson('/api/properties?'.http_build_query([
            'check_in' => '2026-09-09',
            'check_out' => '2026-09-10',
            'guests' => 30,
        ]));

        $response->assertOk();
    });
});
