<?php

declare(strict_types=1);

namespace App\Http\Actions;

use App\Http\Resources\ImportResource;
use App\Models\Import;

final class ShowImportAction
{
    public function __invoke(Import $import): ImportResource
    {
        return new ImportResource($import->load('supplier'));
    }
}
