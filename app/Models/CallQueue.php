<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CallQueue extends Model
{
    use BelongsToOrganization;

    public const STRATEGIES = [
        'rrmemory' => 'Round robin',
        'ringall' => 'Ring all available agents',
        'leastrecent' => 'Longest idle agent',
        'fewestcalls' => 'Fewest calls answered',
        'linear' => 'In listed order',
        'random' => 'Random',
    ];

    protected $fillable = ['organization_id', 'number', 'name', 'strategy', 'agent_timeout', 'wrapup_time', 'max_wait', 'fallback_destination'];

    protected function casts(): array
    {
        return ['agent_timeout' => 'integer', 'wrapup_time' => 'integer', 'max_wait' => 'integer'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Extension::class, 'call_queue_members')->withPivot(['id', 'penalty', 'paused']);
    }

    /** Queue name on the PBX (matches the asterisk_queues view). */
    public function pbxName(): string
    {
        return "o{$this->organization_id}_q{$this->number}";
    }
}
