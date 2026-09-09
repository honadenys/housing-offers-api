<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverImports extends Command
{
    protected $signature = 'imports:recover';

    protected $description = 'Requeue pending imports and fail abandoned processing imports';

    public function handle(): int
    {
        Import::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->where('updated_at', '<', now()->subMinutes(5))
            ->eachById(fn (Import $import) => $this->recoverImport($import));

        return self::SUCCESS;
    }

    private function recoverImport(Import $candidate): void
    {
        DB::transaction(function () use ($candidate): void {
            $import = Import::query()->lockForUpdate()->findOrFail($candidate->id);

            if ($import->updated_at === null || $import->updated_at->greaterThanOrEqualTo(now()->subMinutes(5))) {
                return;
            }

            if ($import->status === ImportStatus::Pending) {
                ProcessImport::dispatch($import->id)->onQueue('imports')->afterCommit();
            } elseif ($import->status === ImportStatus::Processing) {
                $import->update([
                    'status' => ImportStatus::Failed,
                    'error' => 'Import worker stopped before completion.',
                ]);
            }
        });
    }
}
