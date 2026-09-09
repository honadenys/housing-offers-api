<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('import is accepted and queued', function () {
    $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();

    $response = $this->postJson('/api/imports', importPayload());

    $response
        ->assertAccepted()
        ->assertJsonPath('data.status', ImportStatus::Pending->value)
        ->assertJsonPath('data.supplier', $supplier->code)
        ->assertJsonPath('data.total_offers', 1);

    $importId = $response->json('data.id');

    $this->assertDatabaseHas('imports', [
        'id' => $importId,
        'supplier_id' => $supplier->id,
        'external_import_id' => 'import-2026-09-01-001',
        'status' => ImportStatus::Pending->value,
    ]);

    Queue::assertPushed(ProcessImport::class, function (ProcessImport $job) use ($importId): bool {
        return $job->importId === $importId;
    });
});

test('duplicate import returns existing record without duplicate job', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();

    $payload = importPayload();
    $first = $this->postJson('/api/imports', $payload);
    $second = $this->postJson('/api/imports', $payload);

    $first->assertAccepted();
    $second->assertAccepted();
    $this->assertSame($first->json('data.id'), $second->json('data.id'));
    $this->assertDatabaseCount('imports', 1);
    Queue::assertPushed(ProcessImport::class, 1);
});

test('unknown supplier and invalid nested dates are rejected', function () {
    $payload = importPayload();
    $payload['supplier'] = 'missing-supplier';
    $payload['offers'][0]['check_out'] = '2026-10-01';

    $response = $this->postJson('/api/imports', $payload);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supplier', 'offers.0.check_out']);

    $this->assertDatabaseCount('imports', 0);
});

test('job processes offers and exposes completed import', function () {
    $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    $payload = importPayload();
    $import = Import::factory()->create([
        'supplier_id' => $supplier->id,
        'external_import_id' => 'import-process-1',
        'payload' => $payload,
        'total_offers' => 1,
    ]);

    (new ProcessImport($import->id))->handle();

    $import->refresh();

    $this->assertSame(ImportStatus::Completed, $import->status);
    $this->assertSame(1, $import->processed_offers);
    $this->assertNotNull($import->completed_at);
    $this->assertDatabaseHas('properties', [
        'code' => 'BCN-0001',
        'city' => 'Barcelona',
    ]);
    $this->assertDatabaseHas('offers', [
        'supplier_id' => $supplier->id,
        'external_id' => 'offer-a-10001',
        'price' => 72500,
    ]);

    $response = $this->getJson("/api/imports/{$import->id}");

    $response
        ->assertOk()
        ->assertJsonPath('data.status', ImportStatus::Completed->value)
        ->assertJsonPath('data.processed_offers', 1);
});

test('existing offer is updated by a later import', function () {
    $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    $firstPayload = importPayload();
    $firstImport = Import::factory()->create([
        'supplier_id' => $supplier->id,
        'external_import_id' => 'import-first',
        'payload' => $firstPayload,
        'total_offers' => 1,
    ]);

    $secondPayload = importPayload();
    $secondPayload['external_import_id'] = 'import-second';
    $secondPayload['offers'][0]['price'] = 65000;
    $secondPayload['offers'][0]['property']['city'] = 'Madrid';
    $secondImport = Import::factory()->create([
        'supplier_id' => $supplier->id,
        'external_import_id' => 'import-second',
        'payload' => $secondPayload,
        'total_offers' => 1,
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

test('job failure marks import failed', function () {
    $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
    $payload = importPayload();
    unset($payload['offers'][0]['property']['name']);
    $import = Import::factory()->create([
        'supplier_id' => $supplier->id,
        'external_import_id' => 'import-failure',
        'payload' => $payload,
        'total_offers' => 1,
    ]);
    $failure = null;

    try {
        (new ProcessImport($import->id))->handle();
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    $this->assertNotNull($failure);
    $this->assertSame(ImportStatus::Failed, $import->refresh()->status);
    $this->assertNotNull($import->error);
    $this->assertSame(0, $import->processed_offers);
});

test('malformed offer fields are rejected', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();

    foreach (['currency', 'check_in', 'check_out'] as $field) {
        $payload = importPayload();
        $payload['offers'][0][$field] = [];

        $response = $this->postJson('/api/imports', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors("offers.0.{$field}");
    }

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('imports', 0);
});

test('duplicate offer ids are rejected', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    $payload = importPayload();
    $payload['offers'][] = $payload['offers'][0];

    $response = $this->postJson('/api/imports', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors('offers.0.external_id');
    $this->assertDatabaseCount('imports', 0);
});

test('later offer failure rolls back earlier writes', function () {
    $payload = importPayload();
    $payload['offers'][] = $payload['offers'][0];
    $payload['offers'][1]['external_id'] = 'broken-offer';
    unset($payload['offers'][1]['property']['name']);
    $import = Import::factory()->create(['payload' => $payload, 'total_offers' => 2]);
    $failure = null;

    try {
        (new ProcessImport($import->id))->handle();
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    $this->assertNotNull($failure);
    $this->assertSame(ImportStatus::Failed, $import->fresh()->status);
    $this->assertDatabaseCount('offers', 0);
    $this->assertDatabaseCount('properties', 0);
});

test('dispatch failure keeps payload for recovery', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    $dispatcher = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()
        ->andThrow(new RuntimeException('Queue unavailable'));

    $response = $this->postJson('/api/imports', importPayload());

    $response->assertAccepted()->assertJsonPath('data.status', 'pending');
    $this->assertEquals(importPayload(), Import::firstOrFail()->payload);

    Bus::swap($dispatcher);
    Queue::fake();
    $this->travel(6)->minutes();

    $this->artisan('imports:recover')->assertSuccessful();

    Queue::assertPushed(ProcessImport::class, 1);
});

test('worker failure marks processing import failed', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Processing]);

    (new ProcessImport($import->id))->failed(new RuntimeException('Worker timed out'));

    $this->assertSame(ImportStatus::Failed, $import->fresh()->status);
    $this->assertSame('Worker timed out', $import->fresh()->error);
});

test('recovery fails abandoned imports but leaves recent and completed imports alone', function () {
    $abandoned = Import::factory()->create(['status' => ImportStatus::Processing, 'updated_at' => now()->subMinutes(6)]);
    $recent = Import::factory()->create(['status' => ImportStatus::Processing]);
    $completed = Import::factory()->create(['status' => ImportStatus::Completed, 'updated_at' => now()->subMinutes(6)]);

    $this->artisan('imports:recover')->assertSuccessful();
    (new ProcessImport($completed->id))->failed(new RuntimeException('Late failure'));

    $this->assertSame(ImportStatus::Failed, $abandoned->fresh()->status);
    $this->assertSame(ImportStatus::Processing, $recent->fresh()->status);
    $this->assertSame(ImportStatus::Completed, $completed->fresh()->status);
});

test('import accepts different currencies and preserves them', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();
    $payload = importPayload();
    $payload['offers'][] = $payload['offers'][0];
    $payload['offers'][1]['external_id'] = 'usd-offer';
    $payload['offers'][1]['currency'] = 'USD';

    $response = $this->postJson('/api/imports', $payload)->assertAccepted();
    (new ProcessImport($response->json('data.id')))->handle();

    $this->assertDatabaseHas('offers', ['external_id' => 'usd-offer', 'currency' => 'USD']);
    $this->assertDatabaseHas('offers', ['external_id' => 'offer-a-10001', 'currency' => 'EUR']);
});

test('invalid offer structure and storage overflow are rejected before dispatch', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();

    foreach ([['max_guests', 4294967296], ['available_units', 4294967296]] as [$field, $value]) {
        $payload = importPayload();
        $payload['offers'][0][$field] = $value;
        $this->postJson('/api/imports', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors("offers.0.{$field}");
    }

    $payload = importPayload();
    $payload['offers'] = ['named' => $payload['offers'][0]];
    $this->postJson('/api/imports', $payload)->assertUnprocessable()->assertJsonValidationErrors('offers');
    $payload['offers'] = ['invalid'];
    $this->postJson('/api/imports', $payload)->assertUnprocessable()->assertJsonValidationErrors('offers.0');

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('imports', 0);
});

test('supplier identifiers are scoped and properties are shared', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Supplier::factory()->create(['code' => 'supplier-b']);
    Queue::fake();
    $payload = importPayload();
    $first = $this->postJson('/api/imports', $payload)->assertAccepted();
    $payload['supplier'] = 'supplier-b';
    $second = $this->postJson('/api/imports', $payload)->assertAccepted();

    (new ProcessImport($first->json('data.id')))->handle();
    (new ProcessImport($second->json('data.id')))->handle();

    $this->assertDatabaseCount('imports', 2);
    $this->assertDatabaseCount('offers', 2);
    $this->assertDatabaseCount('properties', 1);
    Queue::assertPushed(ProcessImport::class, 2);
});

test('redelivery of completed import does not restore reserved stock', function () {
    $import = Import::factory()->create(['payload' => importPayload()]);
    $job = new ProcessImport($import->id);
    $job->handle();
    $offer = Offer::query()->firstOrFail();
    $offer->decrement('available_units');

    $job->handle();

    $this->assertSame(1, $offer->fresh()->available_units);
    $this->assertDatabaseCount('offers', 1);
});

function importPayload(): array
{
    return [
        'supplier' => 'supplier-a',
        'external_import_id' => 'import-2026-09-01-001',
        'sent_at' => '2026-09-01T10:00:00Z',
        'offers' => [
            [
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
            ],
        ],
    ];
}

test('supplier timestamps with offsets are stored as UTC instants', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();
    $payload = importPayload();
    $payload['sent_at'] = '2026-09-01T12:00:00+02:00';
    $payload['offers'][0]['expires_at'] = '2026-09-10T23:00:00+02:00';

    $response = $this->postJson('/api/imports', $payload)->assertAccepted();
    (new ProcessImport($response->json('data.id')))->handle();

    $this->assertDatabaseHas('imports', ['sent_at' => '2026-09-01 10:00:00']);
    $this->assertDatabaseHas('offers', ['expires_at' => '2026-09-10 21:00:00']);
    $this->getJson('/api/imports/'.$response->json('data.id'))
        ->assertOk()->assertJsonPath('data.sent_at', '2026-09-01T10:00:00+00:00');
});

test('currency must be uppercase and checkout belongs to its own offer', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();
    $payload = importPayload();
    $payload['offers'][0]['currency'] = 'eur';
    $payload['offers'][] = [...$payload['offers'][0], 'external_id' => 'second', 'check_in' => '2026-11-10', 'check_out' => '2026-11-09', 'currency' => 'EUR'];

    $this->postJson('/api/imports', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors(['offers.0.currency', 'offers.1.check_out'])
        ->assertJsonMissingValidationErrors('offers.0.check_out');
    Queue::assertNothingPushed();
});

test('older imports cannot overwrite newer offer data or restore booked stock', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();
    $newer = importPayload();
    $newer['sent_at'] = '2026-09-09T10:05:00Z';
    $newer['offers'][0]['available_units'] = 1;
    $newerId = $this->postJson('/api/imports', $newer)->assertAccepted()->json('data.id');
    (new ProcessImport($newerId))->handle();
    $offer = Offer::firstOrFail();
    $this->postJson('/api/offers/'.$offer->id.'/reservations', [
        'client_reference' => 'stale-import-order', 'customer_name' => 'John Smith', 'customer_email' => 'john@example.com',
    ])->assertCreated();

    $older = importPayload();
    $older['external_import_id'] = 'delayed-import';
    $older['offers'][0]['price'] = 1;
    $older['offers'][0]['property']['city'] = 'Outdated city';
    $olderId = $this->postJson('/api/imports', $older)->assertAccepted()->json('data.id');
    (new ProcessImport($olderId))->handle();

    $this->assertDatabaseHas('offers', ['id' => $offer->id, 'import_id' => $newerId, 'price' => 72500, 'available_units' => 0]);
    $this->assertDatabaseHas('properties', ['code' => 'BCN-0001', 'city' => 'Barcelona']);
    $this->getJson('/api/imports/'.$olderId)->assertOk()->assertJsonPath('data.status', 'completed');
});

test('large imports use bounded batches and rollback earlier batches on failure', function () {
    $payload = importPayload();
    $template = $payload['offers'][0];
    $payload['offers'] = array_map(fn (int $i): array => [...$template, 'external_id' => 'batch-'.$i], range(1, 501));
    $import = Import::factory()->create(['payload' => $payload, 'total_offers' => 501]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    (new ProcessImport($import->id))->handle();
    $this->assertDatabaseCount('offers', 501);
    $this->assertDatabaseCount('properties', 1);
    expect(count($queries))->toBeLessThan(30);
    expect($import->fresh()->processed_offers)->toBe(501);

    $payload['offers'][500]['property']['name'] = null;
    $failed = Import::factory()->create(['payload' => $payload, 'total_offers' => 501]);
    expect(fn () => (new ProcessImport($failed->id))->handle())->toThrow(QueryException::class);
    $this->assertDatabaseCount('offers', 501);
    $this->assertDatabaseMissing('offers', ['supplier_id' => $failed->supplier_id]);
    expect($failed->fresh()->status)->toBe(ImportStatus::Failed);
});

test('opaque identifiers retain case when batching and checking stale imports', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();
    $payload = importPayload();
    $payload['offers'][0]['external_id'] = 'Offer-A';
    $payload['offers'][0]['property']['code'] = 'Property-A';
    $id = $this->postJson('/api/imports', $payload)->assertAccepted()->json('data.id');
    (new ProcessImport($id))->handle();

    $payload['external_import_id'] = 'another-import';
    $payload['offers'][0]['external_id'] = 'offer-a';
    $payload['offers'][0]['property']['code'] = 'property-a';
    $second = $this->postJson('/api/imports', $payload)->assertAccepted()->json('data.id');
    (new ProcessImport($second))->handle();
    $this->assertDatabaseCount('offers', 2);
    $this->assertDatabaseCount('properties', 2);
});

test('equal supplier timestamps use import id to resolve reversed processing order', function () {
    Supplier::factory()->create(['code' => 'supplier-a']);
    Queue::fake();
    $payload = importPayload();
    $first = $this->postJson('/api/imports', $payload)->assertAccepted()->json('data.id');
    $payload['external_import_id'] = 'same-time-later-arrival';
    $payload['offers'][0]['price'] = 65000;
    $second = $this->postJson('/api/imports', $payload)->assertAccepted()->json('data.id');

    (new ProcessImport($second))->handle();
    (new ProcessImport($first))->handle();

    $this->assertDatabaseCount('offers', 1);
    $this->assertDatabaseHas('offers', ['import_id' => $second, 'price' => 65000]);
});
