<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery log for one event; reached only through its (tenant-scoped) Webhook.
 */
class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_id', 'event', 'payload', 'response_status', 'error', 'attempts', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
