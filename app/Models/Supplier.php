<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
    ];

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
