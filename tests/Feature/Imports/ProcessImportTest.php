<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Supplier;
use Illuminate\Support\Facades\Queue;

describe('Import Offer Processing & Upserts', function () {
    it('processes imported offers, creates properties and offers in database, and marks import as completed', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $payload = validImportPayload([
            'supplier' => $supplier->code,
            'external_import_id' => 'import-process-1',
        ]);

        $import = Import::factory()->for($supplier)->create([
            'payload' => $payload,
            'total_offers' => 1,
            'external_import_id' => 'import-process-1',
        ]);

        (new ProcessImport($import->id))->handle();

        $import->refresh();

        expect($import->status)->toBe(ImportStatus::Completed);
        expect($import->processed_offers)->toBe(1);
        expect($import->completed_at)->not->toBeNull();

        $this->assertDatabaseHas('properties', [
            'code' => 'BCN-0001',
            'city' => 'Barcelona',
        ]);

        $this->assertDatabaseHas('offers', [
            'supplier_id' => $supplier->id,
            'external_id' => 'offer-a-10001',
            'price' => 72500,
        ]);

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ImportStatus::Completed->value)
            ->assertJsonPath('data.processed_offers', 1);
    });

    it('updates existing properties and offers when a later import modifies them', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

        $firstImport = Import::factory()->for($supplier)->create([
            'payload' => validImportPayload(['external_import_id' => 'import-first']),
            'total_offers' => 1,
            'external_import_id' => 'import-first',
        ]);

        $updatedOffer = validOfferPayload([
            'price' => 65000,
            'property' => [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Madrid',
            ],
        ]);
        $secondImport = Import::factory()->for($supplier)->create([
            'payload' => validImportPayload([
                'external_import_id' => 'import-second',
                'offers' => [$updatedOffer],
            ]),
            'total_offers' => 1,
            'external_import_id' => 'import-second',
        ]);

        (new ProcessImport($firstImport->id))->handle();
        (new ProcessImport($secondImport->id))->handle();

        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseHas('offers', [
            'id' => Offer::query()->firstOrFail()->id,
            'import_id' => $secondImport->id,
            'price' => 65000,
        ]);

        $this->assertDatabaseHas('properties', [
            'code' => 'BCN-0001',
            'city' => 'Madrid',
        ]);
    });

    it('supports and preserves multiple distinct currencies across offers', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $eurOffer = validOfferPayload(['external_id' => 'eur-offer', 'currency' => 'EUR']);
        $usdOffer = validOfferPayload(['external_id' => 'usd-offer', 'currency' => 'USD']);

        $response = $this->postJson('/api/imports', validImportPayload(['offers' => [$eurOffer, $usdOffer]]))
            ->assertAccepted();

        (new ProcessImport((int) $response->json('data.id')))->handle();

        $this->assertDatabaseHas('offers', ['external_id' => 'usd-offer', 'currency' => 'USD']);
        $this->assertDatabaseHas('offers', ['external_id' => 'eur-offer', 'currency' => 'EUR']);
    });

    it('shares properties across suppliers while scoping offer external IDs to each supplier', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Supplier::factory()->create(['code' => 'supplier-b']);
        Queue::fake();

        $sharedOffer = validOfferPayload([
            'external_id' => 'shared-offer-id',
            'property' => ['code' => 'SHARED-PROP', 'name' => 'Shared Property', 'city' => 'Barcelona'],
        ]);

        $payloadA = validImportPayload(['supplier' => 'supplier-a', 'offers' => [$sharedOffer]]);
        $payloadB = validImportPayload(['supplier' => 'supplier-b', 'offers' => [$sharedOffer]]);

        $firstImportResponse = $this->postJson('/api/imports', $payloadA)->assertAccepted();
        $secondImportResponse = $this->postJson('/api/imports', $payloadB)->assertAccepted();

        (new ProcessImport((int) $firstImportResponse->json('data.id')))->handle();
        (new ProcessImport((int) $secondImportResponse->json('data.id')))->handle();

        $this->assertDatabaseCount('imports', 2);
        $this->assertDatabaseCount('offers', 2);
        $this->assertDatabaseCount('properties', 1);
        Queue::assertPushed(ProcessImport::class, 2);
    });

    it('does not restore inventory for already reserved units when re-processing an import', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $import = Import::factory()->for($supplier)->create([
            'payload' => validImportPayload(),
            'total_offers' => 1,
        ]);
        (new ProcessImport($import->id))->handle();

        $offer = Offer::query()->firstOrFail();
        $offer->decrement('available_units');
        expect($offer->fresh()->available_units)->toBe(1);

        (new ProcessImport($import->id))->handle();

        expect($offer->fresh()->available_units)->toBe(1);
        $this->assertDatabaseCount('offers', 1);
    });

    it('stores timestamps with timezone offsets as normalized UTC instants in the database', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $offer = validOfferPayload(['expires_at' => '2026-09-10T23:00:00+02:00']);
        $payload = validImportPayload([
            'sent_at' => '2026-09-01T12:00:00+02:00',
            'offers' => [$offer],
        ]);

        $response = $this->postJson('/api/imports', $payload)->assertAccepted();
        $importId = (int) $response->json('data.id');
        (new ProcessImport($importId))->handle();

        $this->assertDatabaseHas('imports', ['sent_at' => '2026-09-01 10:00:00']);
        $this->assertDatabaseHas('offers', ['expires_at' => '2026-09-10 21:00:00']);

        $this->getJson("/api/imports/{$importId}")
            ->assertOk()
            ->assertJsonPath('data.sent_at', '2026-09-01T10:00:00+00:00');
    });

    it('preserves case sensitivity for opaque external identifiers and property codes', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $upperOffer = validOfferPayload([
            'external_id' => 'Offer-A',
            'property' => ['code' => 'Property-A', 'name' => 'Upper Prop', 'city' => 'Barcelona'],
        ]);
        $firstId = (int) $this->postJson('/api/imports', validImportPayload(['offers' => [$upperOffer]]))
            ->assertAccepted()
            ->json('data.id');
        (new ProcessImport($firstId))->handle();

        $lowerOffer = validOfferPayload([
            'external_id' => 'offer-a',
            'property' => ['code' => 'property-a', 'name' => 'Lower Prop', 'city' => 'Barcelona'],
        ]);
        $secondId = (int) $this->postJson('/api/imports', validImportPayload([
            'external_import_id' => 'another-import',
            'offers' => [$lowerOffer],
        ]))->assertAccepted()->json('data.id');
        (new ProcessImport($secondId))->handle();

        $this->assertDatabaseCount('offers', 2);
        $this->assertDatabaseCount('properties', 2);
    });
});
