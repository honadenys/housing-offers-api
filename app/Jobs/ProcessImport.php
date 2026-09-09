<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $importId) {}

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(): void
    {
        $import = $this->claimImportForProcessing();

        if ($import === null) {
            return;
        }

        try {
            $this->processImportOffers($import);
        } catch (Throwable $exception) {
            $this->markImportAsFailed($import, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Import worker failed.', ['import_id' => $this->importId, 'exception' => $exception]);

        Import::query()->whereKey($this->importId)
            ->whereNotIn('status', [ImportStatus::Completed->value, ImportStatus::Failed->value])
            ->update([
                'status' => ImportStatus::Failed,
                'error' => mb_substr($exception?->getMessage() ?? 'Import worker failed.', 0, 2000),
            ]);
    }

    private function claimImportForProcessing(): ?Import
    {
        return DB::transaction(function (): ?Import {
            $import = Import::query()
                ->lockForUpdate()
                ->findOrFail($this->importId);

            if ($this->importIsFinished($import)) {
                return null;
            }

            $this->markImportAsProcessing($import);

            return $import;
        });
    }

    private function processImportOffers(Import $import): void
    {
        DB::transaction(function () use ($import): void {
            Supplier::query()->lockForUpdate()->findOrFail($import->supplier_id);
            $import = Import::query()->lockForUpdate()->findOrFail($import->id);

            if ($this->importIsFinished($import)) {
                return;
            }

            $offers = $import->payload['offers'];

            foreach (array_chunk($offers, 500) as $chunk) {
                $this->upsertOffers($import, $chunk);
            }

            $this->markImportAsCompleted($import, count($offers));
        }, attempts: 3);
    }

    /** @param list<array<string, mixed>> $offers */
    private function upsertOffers(Import $import, array $offers): void
    {
        $newerOfferIds = Offer::query()
            ->join('imports', 'imports.id', '=', 'offers.import_id')
            ->where('offers.supplier_id', $import->supplier_id)
            ->whereIn('offers.external_id', array_column($offers, 'external_id'))
            ->where(function (Builder $query) use ($import): void {
                $query->where('imports.sent_at', '>', $import->sent_at)
                    ->orWhere(function (Builder $query) use ($import): void {
                        $query->where('imports.sent_at', $import->sent_at)
                            ->where('imports.id', '>=', $import->id);
                    });
            })
            ->pluck('offers.external_id')
            ->all();

        $newerOfferIds = array_fill_keys($newerOfferIds, true);
        $offers = array_values(array_filter(
            $offers,
            fn (array $offer): bool => ! isset($newerOfferIds[$offer['external_id']]),
        ));

        if ($offers === []) {
            return;
        }

        $properties = [];
        foreach ($offers as $offer) {
            $property = $offer['property'];
            $properties[$property['code']] = [
                'code' => $property['code'],
                'name' => $property['name'],
                'city' => $property['city'],
            ];
        }

        Property::query()->upsert(array_values($properties), ['code'], ['name', 'city', 'updated_at']);
        $propertyIds = Property::query()->whereIn('code', array_keys($properties))->pluck('id', 'code');

        $records = array_map(fn (array $offer): array => [
            'supplier_id' => $import->supplier_id,
            'import_id' => $import->id,
            'property_id' => $propertyIds[$offer['property']['code']],
            'external_id' => $offer['external_id'],
            'check_in' => $offer['check_in'],
            'check_out' => $offer['check_out'],
            'max_guests' => $offer['max_guests'],
            'price' => $offer['price'],
            'currency' => $offer['currency'],
            'available_units' => $offer['available_units'],
            'expires_at' => CarbonImmutable::parse($offer['expires_at'])->utc()->toDateTimeString(),
        ], $offers);

        Offer::query()->upsert($records, ['supplier_id', 'external_id'], [
            'import_id', 'property_id', 'check_in', 'check_out', 'max_guests',
            'price', 'currency', 'available_units', 'expires_at', 'updated_at',
        ]);
    }

    private function importIsFinished(Import $import): bool
    {
        return in_array(
            $import->status,
            [ImportStatus::Completed, ImportStatus::Failed],
            true,
        );
    }

    private function markImportAsProcessing(Import $import): void
    {
        $import->update([
            'status' => ImportStatus::Processing,
            'error' => null,
        ]);
    }

    private function markImportAsCompleted(Import $import, int $processedOffers): void
    {
        $import->update([
            'status' => ImportStatus::Completed,
            'processed_offers' => $processedOffers,
            'completed_at' => now(),
            'error' => null,
        ]);
    }

    private function markImportAsFailed(Import $import, Throwable $exception): void
    {
        Log::error('Import processing failed.', [
            'import_id' => $this->importId,
            'exception' => $exception,
        ]);

        $import->update([
            'status' => ImportStatus::Failed,
            'error' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }
}
