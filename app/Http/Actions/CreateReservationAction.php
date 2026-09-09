<?php

declare(strict_types=1);

namespace App\Http\Actions;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CreateReservationAction
{
    public function __invoke(
        StoreReservationRequest $request,
        Offer $offer,
        ReservationService $service,
    ): JsonResponse {
        $reservation = $service->reserve($offer, $request->validated());

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(
                $reservation->wasRecentlyCreated
                    ? Response::HTTP_CREATED
                    : Response::HTTP_OK,
            );
    }
}
