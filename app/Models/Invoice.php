<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToOrganization;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'number',
        'status',
        'currency',
        'subtotal',
        'tax',
        'total',
        'line_items',
        'period_start',
        'period_end',
        'issued_at',
        'due_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'line_items' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class)->withoutGlobalScopes();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->withoutGlobalScopes();
    }

    public function amountPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function balance(): float
    {
        return round((float) $this->total - $this->amountPaid(), 2);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->due_at->isPast();
    }
}
