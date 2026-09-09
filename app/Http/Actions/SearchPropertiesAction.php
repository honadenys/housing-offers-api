<?php

declare(strict_types=1);

namespace App\Http\Actions;

use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertyCollection;
use App\Services\PropertySearchService;

final class SearchPropertiesAction
{
    public function __invoke(
        SearchPropertiesRequest $request,
        PropertySearchService $service,
    ): PropertyCollection {
        return new PropertyCollection($service->search($request->filters())->withQueryString());
    }
}
