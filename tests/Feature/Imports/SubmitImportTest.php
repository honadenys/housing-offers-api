<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Supplier;
use Illuminate\Support\Facades\Queue;

describe('Import Submission & Idempotency', function () {
    it('accepts a valid import payload, marks it pending, and queues a processing job', function () {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $payload = validImportPayload(['supplier' => $supplier->code]);

        $response = $this->postJson('/api/imports', $payload);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.status', ImportStatus::Pending->value)
            ->assertJsonPath('data.supplier', $supplier->code)
            ->assertJsonPath('data.total_offers', 1);

        $importId = (int) $response->json('data.id');

        $this->assertDatabaseHas('imports', [
            'id' => $importId,
            'supplier_id' => $supplier->id,
            'external_import_id' => $payload['external_import_id'],
            'status' => ImportStatus::Pending->value,
        ]);

        Queue::assertPushed(
            ProcessImport::class,
            fn (ProcessImport $job): bool => $job->importId === $importId
        );
    });

    it('returns the existing import idempotently when the same payload is submitted again', function () {
        Supplier::factory()->create(['code' => 'supplier-a']);
        Queue::fake();

        $payload = validImportPayload();

        $firstSubmission = $this->postJson('/api/imports', $payload);
        $secondSubmission = $this->postJson('/api/imports', $payload);

        $firstSubmission->assertAccepted();
        $secondSubmission->assertAccepted();
        expect($secondSubmission->json('data.id'))->toBe($firstSubmission->json('data.id'));

        $this->assertDatabaseCount('imports', 1);
        Queue::assertPushed(ProcessImport::class, 1);
    });
});
