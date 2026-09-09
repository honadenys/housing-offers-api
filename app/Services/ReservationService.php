<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OfferUnavailableException;
use App\Exceptions\ReservationReferenceConflictException;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ReservationService
{
    /** @param array<string, mixed> $data */
    public function reserve(Offer $offer, array $data): Reservation
    {
        try {
            return DB::transaction(function () use ($offer, $data): Reservation {
                $lockedOffer = Offer::query()->lockForUpdate()->findOrFail($offer->id);

                if ($existing = $this->existingReservation($lockedOffer, $data)) {
                    return $existing;
                }

                if ($lockedOffer->available_units <= 0 || $lockedOffer->expires_at->lessThanOrEqualTo(now())) {
                    throw new OfferUnavailableException;
                }

                $reservation = $lockedOffer->reservations()->create($data);
                $lockedOffer->decrement('available_units');

                return $reservation;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Another offer may have been booked with this reference concurrently.
            return $this->existingReservation($offer, $data) ?? throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    private function existingReservation(Offer $offer, array $data): ?Reservation
    {
        $reservation = Reservation::query()->where('client_reference', $data['client_reference'])->first();

        if ($reservation !== null && (
            $reservation->offer_id !== $offer->id
            || $reservation->customer_name !== $data['customer_name']
            || $reservation->customer_email !== $data['customer_email']
        )) {
            throw new ReservationReferenceConflictException;
        }

        return $reservation;
    }
}
