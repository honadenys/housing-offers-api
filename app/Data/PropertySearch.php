<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class PropertySearch
{
    public function __construct(
        public CarbonImmutable $checkIn,
        public CarbonImmutable $checkOut,
        public int $guests,
        public ?string $city = null,
        public int $perPage = 15,
        public int $page = 1,
        public ?string $currency = null,
    ) {}
}
