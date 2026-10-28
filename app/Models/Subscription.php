<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToOrganization;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses under which the subscription still grants its package limits. */
    public const LIVE_STATUSES = [self::STATUS_TRIALING, self::STATUS_ACTIVE, self::STATUS_PAST_DUE];

    protected $fillable = [
        'organization_id',
        'package_id',
        'status',
        'billing_cycle',
        'price',
        'currency',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Monthly-equivalent revenue for MRR reporting.
     */
    public function monthlyValue(): float
    {
        return $this->billing_cycle === 'annual' ? (float) $this->price / 12 : (float) $this->price;
    }
}
