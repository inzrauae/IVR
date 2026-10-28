<?php

namespace App\Models;

use App\Authorization\Permission;
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
        'did_number_id',
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

    /**
     * Agents and employees (no live-call or report access) see only calls on their own extension.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can(Permission::CALLS_VIEW_LIVE) || $user->can(Permission::REPORTS_VIEW)) {
            return;
        }

        $extensionId = $user->extension?->id;

        $query->where(fn ($q) => $q->where('from_extension_id', $extensionId ?? 0)->orWhere('to_extension_id', $extensionId ?? 0));
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function didNumber(): BelongsTo
    {
        return $this->belongsTo(DidNumber::class)->withoutGlobalScopes();
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class)->withoutGlobalScopes();
    }

    /**
     * The public shape of a call, shared by webhooks and the REST API.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'status' => $this->status,
            'from_number' => $this->from_number,
            'from_name' => $this->from_name,
            'to_number' => $this->to_number,
            'from_extension' => $this->fromExtension?->number,
            'to_extension' => $this->toExtension?->number,
            'started_at' => $this->started_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration' => $this->duration,
            'talk_time' => $this->talk_time,
            'hangup_cause' => $this->hangup_cause,
        ];
    }
}
