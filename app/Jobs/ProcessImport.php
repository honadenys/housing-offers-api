<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
            $import = Import::query()->lockForUpdate()->findOrFail($import->id);

            if ($this->importIsFinished($import)) {
                return;
            }

            $offers = $this->readOffersFromImport($import);

            foreach ($offers as $offerData) {
                $this->saveOfferFromImport($import, $offerData);
            }

            $this->markImportAsCompleted($import, count($offers));
        }, attempts: 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readOffersFromImport(Import $import): array
    {
        $payload = $import->fresh()->payload;

        return $payload['offers'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $offerData
     */
    private function saveOfferFromImport(Import $import, array $offerData): void
    {
        $property = $this->saveProperty($offerData['property']);
        $offerAttributes = $this->offerAttributes($import, $property, $offerData);

        $offer = Offer::query()->createOrFirst(
            [
                'supplier_id' => $import->supplier_id,
                'external_id' => $offerData['external_id'],
            ],
            $offerAttributes,
        );

        $offer->fill($offerAttributes)->save();
    }

    /**
     * @param  array<string, mixed>  $propertyData
     */
    private function saveProperty(array $propertyData): Property
    {
        $propertyAttributes = [
            'name' => $propertyData['name'],
            'city' => $propertyData['city'],
        ];

        $property = Property::query()->createOrFirst(
            ['code' => $propertyData['code']],
            $propertyAttributes,
        );

        $property->fill($propertyAttributes)->save();

        return $property;
    }

    /**
     * @param  array<string, mixed>  $offerData
     * @return array<string, mixed>
     */
    private function offerAttributes(
        Import $import,
        Property $property,
        array $offerData,
    ): array {
        return [
            'import_id' => $import->id,
            'property_id' => $property->id,
            'check_in' => $offerData['check_in'],
            'check_out' => $offerData['check_out'],
            'max_guests' => $offerData['max_guests'],
            'price' => $offerData['price'],
            'currency' => $offerData['currency'],
            'available_units' => $offerData['available_units'],
            'expires_at' => CarbonImmutable::parse($offerData['expires_at'])->utc(),
        ];
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
