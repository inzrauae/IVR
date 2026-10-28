<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class RingGroup extends Model
{
    use BelongsToOrganization;

    public const STRATEGIES = ['ringall' => 'Ring all at once', 'sequential' => 'One after another'];

    protected $fillable = ['organization_id', 'number', 'name', 'strategy', 'ring_timeout', 'members', 'fallback_destination'];

    protected function casts(): array
    {
        return ['members' => 'array', 'ring_timeout' => 'integer'];
    }
}
