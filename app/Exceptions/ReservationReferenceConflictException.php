<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ReservationReferenceConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Client reference is already used for a different reservation request.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'reservation_reference_conflict',
        ], 409);
    }
}
