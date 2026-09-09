<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        Supplier::upsert(
            [
                ['code' => 'supplier-a', 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'supplier-b', 'created_at' => $now, 'updated_at' => $now],
            ],
            ['code'],
            ['updated_at'],
        );
    }
}
