<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Call extends Model
{
    use BelongsToOrganization;

    public const STATUS_RINGING = 'ringing';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MISSED = 'missed';

    public const STATUS_FAILED = 'failed';

    public const LIVE_STATUSES = [self::STATUS_RINGING, self::STATUS_CONNECTED, self::STATUS_ON_HOLD];

    public const DIRECTION_INTERNAL = 'internal';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'organization_id',
        'pbx_server_id',
        'linked_id',
        'direction',
        'status',
        'from_number',
        'from_name',
        'to_number',
        'from_extension_id',
        'to_extension_id',
        'active_channels',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'active_channels' => 'array',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function fromExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'from_extension_id')->withoutGlobalScopes();
    }

    public function toExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'to_extension_id')->withoutGlobalScopes();
    }

    public function pbxServer(): BelongsTo
    {
        return $this->belongsTo(PbxServer::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function scopeFinished(Builder $query): void
    {
        $query->whereNotIn('status', self::LIVE_STATUSES);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }
}
