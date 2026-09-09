<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ImportService
{
    public function createAndDispatch(array $payload): Import
    {
        $supplier = Supplier::query()
            ->where('code', $payload['supplier'])
            ->firstOrFail();

        $import = DB::transaction(function () use ($payload, $supplier): Import {
            $import = Import::query()->createOrFirst(
                [
                    'supplier_id' => $supplier->id,
                    'external_import_id' => $payload['external_import_id'],
                ],
                [
                    'sent_at' => CarbonImmutable::parse($payload['sent_at'])->utc(),
                    'payload' => $payload,
                    'status' => 'pending',
                    'total_offers' => count($payload['offers']),
                    'processed_offers' => 0,
                ],
            );

            if ($import->wasRecentlyCreated) {
                DB::afterCommit(fn () => $this->dispatchImport($import));
            }

            return $import;
        });

        return $import->load('supplier');
    }

    private function dispatchImport(Import $import): void
    {
        try {
            ProcessImport::dispatch($import->id)->onQueue('imports');
        } catch (Throwable $exception) {
            // The committed payload remains available to imports:recover.
            Log::error('Import dispatch failed; recovery will retry.', [
                'import_id' => $import->id,
                'exception' => $exception,
            ]);
        }
    }
}
