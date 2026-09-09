<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setCollation('utf8mb4_bin');
    }

    public function down(): void
    {
        $this->setCollation('utf8mb4_unicode_ci');
    }

    private function setCollation(string $collation): void
    {
        foreach ([
            'suppliers' => ['code', 100],
            'properties' => ['code', 100],
            'imports' => ['external_import_id', 191],
            'offers' => ['external_id', 191],
            'reservations' => ['client_reference', 191],
        ] as $name => [$column, $length]) {
            Schema::table($name, function (Blueprint $table) use ($column, $length, $collation): void {
                $table->string($column, $length)->collation($collation)->change();
            });
        }
    }
};
