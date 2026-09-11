<?php

declare(strict_types=1);

use App\Models\Supplier;
use Illuminate\Support\Facades\Queue;

describe('Import Payload Validation Rules', function () {
    it('rejects imports referencing an unknown supplier', function () {
        $payload = validImportPayload(['supplier' => 'non-existent-supplier']);

        $response = $this->postJson('/api/imports', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier']);

        $this->assertDatabaseCount('imports', 0);
    });

    it('rejects imports where offer checkout date is on or before check-in date', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $invalidOffer = validOfferPayload([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-01',
        ]);
        $payload = validImportPayload(['offers' => [$invalidOffer]]);

        $response = $this->postJson('/api/imports', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers.0.check_out']);

        $this->assertDatabaseCount('imports', 0);
    });

    it('rejects non-scalar values for offer fields', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        foreach (['currency', 'check_in', 'check_out'] as $field) {
            $invalidOffer = validOfferPayload([$field => []]);
            $payload = validImportPayload(['offers' => [$invalidOffer]]);

            $this->postJson('/api/imports', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors("offers.0.{$field}");
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('imports', 0);
    });

    it('rejects duplicate offer external IDs within the same import payload', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $offer = validOfferPayload(['external_id' => 'duplicate-offer-id']);
        $payload = validImportPayload(['offers' => [$offer, $offer]]);

        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers.0.external_id');

        $this->assertDatabaseCount('imports', 0);
    });

    it('rejects integer quantities that exceed MySQL unsigned integer limits', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $overflowValue = 4294967296;

        foreach (['max_guests', 'available_units'] as $field) {
            $overflowOffer = validOfferPayload([$field => $overflowValue]);
            $payload = validImportPayload(['offers' => [$overflowOffer]]);

            $this->postJson('/api/imports', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors("offers.0.{$field}");
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('imports', 0);
    });

    it('rejects non-list offer payloads and non-object offer items', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $assocPayload = validImportPayload();
        $assocPayload['offers'] = ['named' => validOfferPayload()];

        $this->postJson('/api/imports', $assocPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers');

        $scalarPayload = validImportPayload();
        $scalarPayload['offers'] = ['invalid-scalar-item'];

        $this->postJson('/api/imports', $scalarPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers.0');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('imports', 0);
    });

    it('requires currency to be uppercase and associates date validation errors with their specific offer index', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $offerWithLowercaseCurrency = validOfferPayload(['currency' => 'eur']);
        $offerWithInvertedDates = validOfferPayload([
            'external_id' => 'second-offer',
            'check_in' => '2026-11-10',
            'check_out' => '2026-11-09',
            'currency' => 'EUR',
        ]);

        $payload = validImportPayload([
            'offers' => [$offerWithLowercaseCurrency, $offerWithInvertedDates],
        ]);

        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offers.0.currency', 'offers.1.check_out'])
            ->assertJsonMissingValidationErrors('offers.0.check_out');

        Queue::assertNothingPushed();
    });
});
