<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Extension extends Model
{
    /** @use HasFactory<\Database\Factories\ExtensionFactory> */
    use BelongsToOrganization, HasFactory;

    public const PROVISION_PENDING = 'pending';

    public const PROVISION_OK = 'provisioned';

    public const PROVISION_FAILED = 'failed';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const FIRST_NUMBER = 100;

    protected $fillable = [
        'organization_id',
        'user_id',
        'department_id',
        'number',
        'name',
        'caller_id_name',
        'caller_id_number',
        'webrtc_enabled',
        'voicemail_enabled',
        'do_not_disturb',
        'ring_timeout',
        'forward_always',
        'forward_busy',
        'forward_no_answer',
        'status',
    ];

    protected $hidden = ['sip_password'];

    protected function casts(): array
    {
        return [
            'sip_password' => 'encrypted',
            'webrtc_enabled' => 'boolean',
            'voicemail_enabled' => 'boolean',
            'do_not_disturb' => 'boolean',
            'registered' => 'boolean',
            'provisioned_at' => 'datetime',
            'registration_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Extension $extension) {
            $extension->sip_username ??= self::sipUsernameFor($extension->organization_id, $extension->number);
            $extension->sip_password ??= self::generatePassword();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Mark the extension as waiting for the PBX to confirm its configuration.
     */
    public function markProvisioningPending(): void
    {
        $this->forceFill(['provisioning_status' => self::PROVISION_PENDING, 'provisioning_error' => null])->save();
    }

    /**
     * PJSIP object id. Encodes the tenant so events can be attributed without a lookup.
     */
    public static function sipUsernameFor(int $organizationId, string $number): string
    {
        return "o{$organizationId}_{$number}";
    }

    /**
     * @return array{organization_id: int, number: string}|null
     */
    public static function parseSipUsername(string $username): ?array
    {
        return preg_match('/^o(\d+)_(\d{2,10})$/', $username, $m)
            ? ['organization_id' => (int) $m[1], 'number' => $m[2]]
            : null;
    }

    public static function generatePassword(): string
    {
        return Str::password(24, symbols: false);
    }

    public static function nextNumber(int $organizationId): string
    {
        $taken = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->pluck('number')
            ->map(fn ($n) => (int) $n)
            ->flip();

        $candidate = self::FIRST_NUMBER;
        while ($taken->has($candidate)) {
            $candidate++;
        }

        return (string) $candidate;
    }
}
