<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-09T10:00:00Z'));
})->in('Feature');
