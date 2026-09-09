<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'external_import_id',
        'sent_at',
        'payload',
        'status',
        'total_offers',
        'processed_offers',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'payload' => 'array',
            'status' => ImportStatus::class,
            'total_offers' => 'integer',
            'processed_offers' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
