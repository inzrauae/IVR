<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DidNumber extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'sip_trunk_id', 'number', 'description', 'destination', 'after_hours_destination', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class, 'sip_trunk_id');
    }

    public static function normalize(string $number): string
    {
        return preg_replace('/\D/', '', $number);
    }
}
