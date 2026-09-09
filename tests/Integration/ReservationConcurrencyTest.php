<?php

declare(strict_types=1);

use App\Models\Offer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

test('concurrent HTTP reservations cannot consume the final unit twice', function (array $references, array $expectedStatuses, bool $expireWhileWaiting) {
    expect(DB::getDriverName())->toBe('mysql');
    expect(DB::getDatabaseName())->toEndWith('_test');
    $offer = Offer::factory()->create(['available_units' => 1]);
    $connection = config('database.connections.mysql');
    $processes = [];

    DB::beginTransaction();
    Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

    try {
        foreach ($references as $reference) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/reserve.php'), (string) $offer->id, $reference], base_path(), [
                'APP_ENV' => 'testing',
                'APP_KEY' => config('app.key'),
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $connection['host'],
                'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => DB::getDatabaseName(),
                'DB_USERNAME' => $connection['username'],
                'DB_PASSWORD' => $connection['password'],
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
            ]);
            $process->setTimeout(15);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 10;
        $waiting = 0;
        do {
            $ids = [];
            foreach ($processes as $process) {
                $line = strtok($process->getOutput(), "\n");
                if ($line !== false) {
                    $ids[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR)['connection'];
                }
                if (! $process->isRunning()) {
                    $this->fail('Reservation process exited before blocking: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            if (count($ids) === 2) {
                $waiting = (int) DB::selectOne(
                    'SELECT COUNT(DISTINCT t.PROCESSLIST_ID) AS total
                     FROM performance_schema.data_lock_waits w
                     JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID
                     WHERE t.PROCESSLIST_ID IN (?, ?)',
                    $ids,
                )->total;
            }
            if ($waiting < 2) {
                usleep(10000);
            }
        } while ($waiting < 2 && microtime(true) < $deadline);

        expect($waiting)->toBe(2, 'Both independent MySQL sessions must be waiting on the held offer lock.');
        if ($expireWhileWaiting) {
            $offer->update(['expires_at' => now()->subSecond()]);
        }
        DB::commit();

        $responses = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $lines = explode("\n", trim($process->getOutput()));
            $responses[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($responses, 'status');
        sort($statuses);
        expect($statuses)->toBe($expectedStatuses);
        expect($offer->fresh()->available_units)->toBe($expireWhileWaiting ? 1 : 0);
        $this->assertDatabaseCount('reservations', $expireWhileWaiting ? 0 : 1);
        if ($references[0] === $references[1]) {
            expect($responses[0]['body']['data']['id'])->toBe($responses[1]['body']['data']['id']);
        }
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
})->with([
    'different orders' => [['order-a', 'order-b'], [201, 409], false],
    'expires while waiting' => [['order-a', 'order-b'], [409, 409], true],
    'same order retried' => [['same-order', 'same-order'], [200, 201], false],
]);
