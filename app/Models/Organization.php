<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'pbx_server_id',
        'timezone',
        'locale',
        'currency',
        'contact_email',
        'contact_phone',
        'address',
        'billing_email',
        'settings',
    ];

    public const SUSPENDED_BY_ADMIN = 'admin';

    public const SUSPENDED_BY_BILLING = 'billing';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pbxServer(): BelongsTo
    {
        return $this->belongsTo(PbxServer::class);
    }

    public function subscriptions(): HasMany
    {
        // Subscriptions are tenant-scoped; this relation is always constrained by organization_id
        // so it is safe to bypass the global scope here (e.g. from the platform portal).
        return $this->hasMany(Subscription::class)->withoutGlobalScopes();
    }

    /**
     * The subscription currently governing limits and features.
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->withoutGlobalScopes()
            ->ofMany(['id' => 'max'], fn ($query) => $query->withoutGlobalScopes()->whereIn('status', Subscription::LIVE_STATUSES));
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function userLimit(): ?int
    {
        $package = $this->currentSubscription?->package;

        // No live subscription means no seats beyond those already present.
        if (! $package) {
            return 0;
        }

        return $package->max_users;
    }

    public function hasUserCapacity(): bool
    {
        $limit = $this->userLimit();

        return $limit === null || $this->users()->count() < $limit;
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class)->withoutGlobalScopes();
    }

    public function extensionLimit(): ?int
    {
        $package = $this->currentSubscription?->package;

        return $package ? $package->max_extensions : 0;
    }

    public function hasExtensionCapacity(): bool
    {
        $limit = $this->extensionLimit();

        return $limit === null || $this->extensions()->count() < $limit;
    }

    public function suspend(string $reason, string $source = self::SUSPENDED_BY_ADMIN): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
            'suspension_source' => $source,
        ])->save();
    }

    public function reactivate(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspension_source' => null,
        ])->save();
    }

    /**
     * Settings merged over defaults, so every key is always present.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolvedSettings(): array
    {
        return array_replace_recursive(OrganizationSettings::defaults(), $this->settings ?? []);
    }

    public function setting(string $key): mixed
    {
        return data_get($this->resolvedSettings(), $key);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function updateSettingsSection(string $section, array $values): void
    {
        $settings = $this->settings ?? [];
        $settings[$section] = $values;
        $this->forceFill(['settings' => $settings])->save();
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class)->withoutGlobalScopes()->orderBy('day_of_week');
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->withoutGlobalScopes()->orderBy('date');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->withoutGlobalScopes();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->withoutGlobalScopes();
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? route('organization-settings.logo', ['v' => $this->updated_at?->timestamp]) : null;
    }
}
