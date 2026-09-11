<?php

declare(strict_types=1);

describe('API Error Handling', function () {
    it('returns a JSON 404 error when requesting a non-existent import ID without an accept header', function () {
        $response = $this->get('/api/imports/999999');

        $response
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    });

    it('returns a JSON 404 error when attempting to reserve an offer that does not exist without an accept header', function () {
        $payload = validReservationPayload([
            'client_reference' => 'missing-offer-test',
        ]);

        $response = $this->post('/api/offers/999999/reservations', $payload);

        $response
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    });
});
