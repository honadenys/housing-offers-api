<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;

final class PropertySearchService
{
    public function search(array $filters): LengthAwarePaginator
    {
        $rankedOffers = Offer::query()
            ->select([
                'offers.id as best_offer_id',
                'offers.supplier_id',
                'offers.property_id',
                'offers.price as best_offer_price',
                'offers.currency as best_offer_currency',
                'offers.available_units as best_offer_available_units',
                'offers.expires_at as best_offer_expires_at',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price ASC, offers.id ASC) AS offer_rank',
            )
            ->where('offers.check_in', $filters['check_in'])
            ->where('offers.check_out', $filters['check_out'])
            ->where('offers.max_guests', '>=', $filters['guests'])
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now());

        $query = Property::query()
            ->select([
                'properties.id',
                'properties.code',
                'properties.name',
                'properties.city',
                'best_offers.best_offer_id',
                'suppliers.code as best_offer_supplier',
                'best_offers.best_offer_price',
                'best_offers.best_offer_currency',
                'best_offers.best_offer_available_units',
                'best_offers.best_offer_expires_at',
            ])
            ->joinSub($rankedOffers, 'best_offers', function (JoinClause $join): void {
                $join->on('properties.id', '=', 'best_offers.property_id')
                    ->where('best_offers.offer_rank', '=', 1);
            })
            ->join('suppliers', 'suppliers.id', '=', 'best_offers.supplier_id')
            ->when(
                $filters['city'] ?? null,
                fn (Builder $builder, string $city): Builder => $builder->where('properties.city', $city),
            )
            ->orderBy('best_offers.best_offer_price')
            ->orderBy('properties.id');

        return $query
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }
}
