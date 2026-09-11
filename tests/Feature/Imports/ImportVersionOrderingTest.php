<?php

declare(strict_types=1);

use App\Jobs\ProcessImport;
use App\Models\Offer;
use App\Models\Supplier;
use Illuminate\Support\Facades\Queue;

describe('Import Version Ordering & Stale Update Protection', function () {
    it('ignores stale older imports and prevents them from overwriting newer offer data or booked stock', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $newerOffer = validOfferPayload([
            'available_units' => 1,
            'price' => 72500,
            'property' => ['code' => 'BCN-0001', 'name' => 'Barcelona Apt', 'city' => 'Barcelona'],
        ]);
        $newerPayload = validImportPayload([
            'sent_at' => '2026-09-09T10:05:00Z',
            'offers' => [$newerOffer],
        ]);

        $newerId = (int) $this->postJson('/api/imports', $newerPayload)->assertAccepted()->json('data.id');
        (new ProcessImport($newerId))->handle();

        $offer = Offer::firstOrFail();
        $this->postJson("/api/offers/{$offer->id}/reservations", validReservationPayload(['client_reference' => 'stale-import-order']))
            ->assertCreated();

        $olderOffer = validOfferPayload([
            'price' => 1,
            'property' => ['code' => 'BCN-0001', 'name' => 'Barcelona Apt', 'city' => 'Outdated city'],
        ]);
        $olderPayload = validImportPayload([
            'external_import_id' => 'delayed-import',
            'sent_at' => '2026-09-09T10:00:00Z',
            'offers' => [$olderOffer],
        ]);

        $olderId = (int) $this->postJson('/api/imports', $olderPayload)->assertAccepted()->json('data.id');
        (new ProcessImport($olderId))->handle();

        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'import_id' => $newerId,
            'price' => 72500,
            'available_units' => 0,
        ]);
        $this->assertDatabaseHas('properties', [
            'code' => 'BCN-0001',
            'city' => 'Barcelona',
        ]);

        $this->getJson("/api/imports/{$olderId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    });

    it('resolves reversed processing order using import ID when supplier timestamps are identical', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $firstPayload = validImportPayload([
            'external_import_id' => 'import-first',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [validOfferPayload(['price' => 72500])],
        ]);
        $firstImportId = (int) $this->postJson('/api/imports', $firstPayload)->assertAccepted()->json('data.id');

        $secondPayload = validImportPayload([
            'external_import_id' => 'import-second-later-arrival',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [validOfferPayload(['price' => 65000])],
        ]);
        $secondImportId = (int) $this->postJson('/api/imports', $secondPayload)->assertAccepted()->json('data.id');

        (new ProcessImport($secondImportId))->handle();
        (new ProcessImport($firstImportId))->handle();

        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseHas('offers', [
            'import_id' => $secondImportId,
            'price' => 65000,
        ]);
    });
});
