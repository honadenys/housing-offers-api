<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('city', 100)->index();
            $table->timestamps();
        });

        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('external_import_id', 191);
            $table->dateTime('sent_at');
            $table->json('payload');
            $table->enum('status', array_map(
                static fn (ImportStatus $status): string => $status->value,
                ImportStatus::cases(),
            ))->default(ImportStatus::Pending->value);
            $table->unsignedInteger('total_offers')->default(0);
            $table->unsignedInteger('processed_offers')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'external_import_id']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 191);
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedInteger('max_guests');
            $table->unsignedBigInteger('price');
            $table->char('currency', 3);
            $table->unsignedInteger('available_units')->default(0);
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['supplier_id', 'external_id']);
            $table->index([
                'property_id',
                'check_in',
                'check_out',
                'max_guests',
                'available_units',
                'expires_at',
                'price',
                'id',
            ], 'offers_property_search_index');
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->string('client_reference', 191)->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->timestamps();

            $table->index('offer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('suppliers');
    }
};
