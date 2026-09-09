<?php

declare(strict_types=1);

test('unknown resources return JSON errors without an accept header', function () {
    $this->get('/api/imports/999999')->assertNotFound()->assertJsonStructure(['message']);
    $this->post('/api/offers/999999/reservations', [
        'client_reference' => 'missing-offer',
        'customer_name' => 'John Smith',
        'customer_email' => 'john@example.com',
    ])->assertNotFound()->assertJsonStructure(['message']);
});
