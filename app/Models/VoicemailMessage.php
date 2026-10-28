<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoicemailMessage extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'extension_id', 'pbx_server_id', 'name', 'caller_number', 'caller_name', 'duration', 'heard_at'];

    protected function casts(): array
    {
        return ['heard_at' => 'datetime'];
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    public function pbxServer(): BelongsTo
    {
        return $this->belongsTo(PbxServer::class);
    }
}
