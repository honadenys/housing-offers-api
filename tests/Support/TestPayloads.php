<?php

declare(strict_types=1);

namespace Tests\Support;

final class TestPayloads
{
    public static function validOffer(array $overrides = []): array
    {
        $default = [
            'external_id' => 'offer-a-10001',
            'property' => [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59Z',
        ];

        return array_replace_recursive($default, $overrides);
    }

    public static function validImport(array $overrides = []): array
    {
        $offers = $overrides['offers'] ?? [self::validOffer()];
        unset($overrides['offers']);

        $default = [
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
        ];

        $payload = array_replace_recursive($default, $overrides);
        $payload['offers'] = $offers;

        return $payload;
    }

    public static function batchOffers(int $count, array $baseOverrides = []): array
    {
        $template = self::validOffer($baseOverrides);

        return array_map(
            fn (int $i): array => array_merge($template, ['external_id' => 'batch-'.$i]),
            range(1, $count),
        );
    }

    public static function validReservation(array $overrides = []): array
    {
        $default = [
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ];

        return array_replace_recursive($default, $overrides);
    }
}
