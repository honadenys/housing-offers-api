<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

describe('Import Batch Rollbacks & Scheduled Recovery', function () {
    it('processes large imports and persists all offers successfully', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $largeBatchOffers = batchOffers(501);
        $import = Import::factory()->for($supplier)->create([
            'payload' => validImportPayload(['offers' => $largeBatchOffers]),
            'total_offers' => 501,
        ]);

        (new ProcessImport($import->id))->handle();

        $this->assertDatabaseCount('offers', 501);
        $this->assertDatabaseCount('properties', 1);
        expect($import->fresh()->processed_offers)->toBe(501);
    });

    it('rolls back earlier writes if a subsequent offer within the payload fails', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $firstOffer = validOfferPayload(['external_id' => 'valid-offer-1']);
        $secondOffer = validOfferPayload(['external_id' => 'broken-offer-2']);
        unset($secondOffer['property']['name']);

        $import = Import::factory()->for($supplier)->create([
            'payload' => validImportPayload(['offers' => [$firstOffer, $secondOffer]]),
            'total_offers' => 2,
        ]);

        $caughtException = null;
        try {
            (new ProcessImport($import->id))->handle();
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        expect($caughtException)->not->toBeNull();
        expect($import->fresh()->status)->toBe(ImportStatus::Failed);
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('properties', 0);
    });

    it('marks import as failed and records the error message if processing fails', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $invalidOffer = validOfferPayload();
        unset($invalidOffer['property']['name']);

        $import = Import::factory()->for($supplier)->create([
            'payload' => validImportPayload(['offers' => [$invalidOffer]]),
            'total_offers' => 1,
            'external_import_id' => 'import-failure-test',
        ]);

        $caughtException = null;
        try {
            (new ProcessImport($import->id))->handle();
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        expect($caughtException)->not->toBeNull();
        expect($import->refresh()->status)->toBe(ImportStatus::Failed);
        expect($import->error)->not->toBeNull();
        expect($import->processed_offers)->toBe(0);
    });

    it('marks processing import as failed when the queue worker triggers a failure callback', function () {
        $import = Import::factory()->create(['status' => ImportStatus::Processing]);

        (new ProcessImport($import->id))->failed(new RuntimeException('Worker timed out'));

        expect($import->fresh()->status)->toBe(ImportStatus::Failed);
        expect($import->fresh()->error)->toBe('Worker timed out');
    });

    it('keeps pending import payload intact for recovery if initial job dispatch fails', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        $originalDispatcher = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()
            ->andThrow(new RuntimeException('Queue connection unavailable'));

        $response = $this->postJson('/api/imports', validImportPayload());

        $response
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $savedImport = Import::firstOrFail();
        expect($savedImport->payload)->toEqual(validImportPayload());

        Bus::swap($originalDispatcher);
        Queue::fake();
        $this->travel(6)->minutes();

        $this->artisan('imports:recover')->assertSuccessful();

        Queue::assertPushed(ProcessImport::class, 1);
    });

    it('fails abandoned imports while leaving recent and completed imports untouched during recovery', function () {
        $abandonedImport = Import::factory()->create([
            'status' => ImportStatus::Processing,
            'updated_at' => now()->subMinutes(6),
        ]);
        $recentImport = Import::factory()->create([
            'status' => ImportStatus::Processing,
        ]);
        $completedImport = Import::factory()->create([
            'status' => ImportStatus::Completed,
            'updated_at' => now()->subMinutes(6),
        ]);

        $this->artisan('imports:recover')->assertSuccessful();

        (new ProcessImport($completedImport->id))->failed(new RuntimeException('Late worker failure'));

        expect($abandonedImport->fresh()->status)->toBe(ImportStatus::Failed);
        expect($recentImport->fresh()->status)->toBe(ImportStatus::Processing);
        expect($completedImport->fresh()->status)->toBe(ImportStatus::Completed);
    });
});
