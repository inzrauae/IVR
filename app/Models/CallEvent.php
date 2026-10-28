<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw PBX event attached to a call; reached only through its (tenant-scoped) Call.
 */
class CallEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['call_id', 'type', 'channel', 'data', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
