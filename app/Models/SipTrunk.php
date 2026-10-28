<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SipTrunk extends Model
{
    use BelongsToOrganization;

    public const TRANSPORTS = ['udp', 'tcp', 'tls'];

    protected $fillable = [
        'organization_id', 'name', 'host', 'port', 'transport', 'username', 'password', 'register',
        'from_domain', 'caller_id', 'max_channels', 'codecs', 'status',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'register' => 'boolean',
            'port' => 'integer',
            'max_channels' => 'integer',
            'provisioned_at' => 'datetime',
        ];
    }

    public function didNumbers(): HasMany
    {
        return $this->hasMany(DidNumber::class);
    }

    public function outboundRoutes(): HasMany
    {
        return $this->hasMany(OutboundRoute::class);
    }

    public function isActive(): bool
    {
        return $this->status === Extension::STATUS_ACTIVE;
    }

    /**
     * PJSIP object id on the PBX, e.g. o12_t3. Never matches an extension id (those are numeric).
     */
    public function endpointId(): string
    {
        return self::endpointIdFor($this->organization_id, $this->id);
    }

    public static function endpointIdFor(int $organizationId, int $trunkId): string
    {
        return "o{$organizationId}_t{$trunkId}";
    }

    /**
     * @return array{organization_id: int, trunk_id: int}|null
     */
    public static function parseEndpointId(string $id): ?array
    {
        return preg_match('/^o(\d+)_t(\d+)$/', $id, $m) ? ['organization_id' => (int) $m[1], 'trunk_id' => (int) $m[2]] : null;
    }

    public function markProvisioningPending(): void
    {
        $this->forceFill(['provisioning_status' => Extension::PROVISION_PENDING, 'provisioning_error' => null])->save();
    }
}
