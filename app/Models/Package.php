<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    /** @use HasFactory<\Database\Factories\PackageFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'currency',
        'monthly_price',
        'annual_price',
        'max_users',
        'max_extensions',
        'max_concurrent_calls',
        'recording_storage_mb',
        'trial_days',
        'overage_per_extension',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'overage_per_extension' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PackageFeature::class);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->features->contains('feature', $feature);
    }
}
