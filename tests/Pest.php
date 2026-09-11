<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPayloads;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-09T10:00:00Z'));
    })
    ->in('Feature');

function validImportPayload(array $overrides = []): array
{
    return TestPayloads::validImport($overrides);
}

function validOfferPayload(array $overrides = []): array
{
    return TestPayloads::validOffer($overrides);
}

function validReservationPayload(array $overrides = []): array
{
    return TestPayloads::validReservation($overrides);
}

function batchOffers(int $count, array $baseOverrides = []): array
{
    return TestPayloads::batchOffers($count, $baseOverrides);
}
