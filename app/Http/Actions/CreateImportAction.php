<?php

declare(strict_types=1);

namespace App\Http\Actions;

use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateImportAction
{
    public function __invoke(StoreImportRequest $request, ImportService $service): JsonResponse
    {
        $import = $service->createAndDispatch($request->validated());

        return (new ImportResource($import))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
