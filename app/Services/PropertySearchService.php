<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\PropertySearch;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;

final class PropertySearchService
{
    /** @return Paginator<int, Property> */
    public function search(PropertySearch $filters): Paginator
    {
        $rankedOffers = Offer::query()
            ->join('properties', 'properties.id', '=', 'offers.property_id')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->select([
                'properties.id',
                'properties.code',
                'properties.name',
                'properties.city',
                'offers.id as best_offer_id',
                'suppliers.code as best_offer_supplier',
                'offers.price as best_offer_price',
                'offers.currency as best_offer_currency',
                'offers.available_units as best_offer_available_units',
                'offers.expires_at as best_offer_expires_at',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price, offers.id) AS offer_rank')
            ->where('offers.check_in', $filters->checkIn->toDateString())
            ->where('offers.check_out', $filters->checkOut->toDateString())
            ->where('offers.max_guests', '>=', $filters->guests)
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now())
            ->when(
                $filters->currency !== null,
                fn (Builder $query): Builder => $query->where('offers.currency', $filters->currency),
            )
            ->when(
                $filters->city !== null,
                fn (Builder $query): Builder => $query->where('properties.city', $filters->city),
            );

        return Property::query()
            ->fromSub($rankedOffers, 'properties')
            ->where('offer_rank', 1)
            ->orderBy('best_offer_price')
            ->orderBy('id')
            ->simplePaginate($filters->perPage, page: $filters->page);
    }
}
