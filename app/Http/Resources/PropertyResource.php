<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'city' => $this->city,
            'best_offer' => [
                'id' => (int) $this->best_offer_id,
                'supplier' => $this->best_offer_supplier,
                'price' => (int) $this->best_offer_price,
                'currency' => $this->best_offer_currency,
                'available_units' => (int) $this->best_offer_available_units,
                'expires_at' => CarbonImmutable::parse($this->best_offer_expires_at)->toIso8601String(),
            ],
        ];
    }
}
