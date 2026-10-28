<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webhook extends Model
{
    use BelongsToOrganization;

    public const EVENTS = [
        'call.started' => 'A call starts ringing',
        'call.answered' => 'A call is answered',
        'call.ended' => 'A call ends (completed, missed or failed)',
        'voicemail.received' => 'A voicemail is left',
        'recording.ready' => 'A call recording is available',
    ];

    protected $fillable = ['organization_id', 'url', 'events', 'secret', 'active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'active' => 'boolean'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
