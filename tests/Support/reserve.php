<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (! $app->environment('testing') || ! str_ends_with(DB::getDatabaseName(), '_test')) {
    throw new RuntimeException('Reservation process requires a dedicated test database.');
}

echo json_encode(['connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR).PHP_EOL;
flush();

$request = Request::create(
    '/api/offers/'.$argv[1].'/reservations',
    'POST',
    server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    content: json_encode([
        'client_reference' => $argv[2],
        'customer_name' => 'John Smith',
        'customer_email' => 'john@example.com',
    ], JSON_THROW_ON_ERROR),
);
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR).PHP_EOL;
$kernel->terminate($request, $response);
