<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\Paginator;

class PropertyCollection extends ResourceCollection
{
    public $collects = PropertyResource::class;

    public function toArray(Request $request): array
    {
        /** @var Paginator $paginator */
        $paginator = $this->resource;

        return [
            'data' => $this->collection->map(
                fn (PropertyResource $resource): array => $resource->resolve($request),
            )->all(),
            'next' => $paginator->nextPageUrl(),
            'prev' => $paginator->previousPageUrl(),
            'per_page' => $paginator->perPage(),
        ];
    }

    public function toResponse($request): JsonResponse
    {
        return response()->json($this->resolve($request));
    }
}
