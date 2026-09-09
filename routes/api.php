<?php

declare(strict_types=1);

use App\Http\Actions\CreateImportAction;
use App\Http\Actions\CreateReservationAction;
use App\Http\Actions\SearchPropertiesAction;
use App\Http\Actions\ShowImportAction;
use Illuminate\Support\Facades\Route;

Route::post('/imports', CreateImportAction::class);
Route::get('/imports/{import}', ShowImportAction::class);
Route::get('/properties', SearchPropertiesAction::class);
Route::post('/offers/{offer}/reservations', CreateReservationAction::class);
