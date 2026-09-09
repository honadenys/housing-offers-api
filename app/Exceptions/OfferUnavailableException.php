<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class OfferUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Offer is no longer available.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'offer_unavailable',
        ], 409);
    }
}
