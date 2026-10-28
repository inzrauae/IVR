<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PbxServer extends Model
{
    /** @use HasFactory<\Database\Factories\PbxServerFactory> */
    use HasFactory;

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    protected $fillable = [
        'name',
        'driver',
        'region',
        'sip_domain',
        'wss_url',
        'ari_url',
        'ari_username',
        'ari_password',
        'ami_host',
        'ami_port',
        'ami_username',
        'ami_secret',
        'is_active',
    ];

    protected $hidden = [
        'ari_password',
        'ami_secret',
    ];

    protected function casts(): array
    {
        return [
            'ari_password' => 'encrypted',
            'ami_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_health_check_at' => 'datetime',
            'last_health_payload' => 'array',
        ];
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }
}
