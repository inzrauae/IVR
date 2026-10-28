<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recording extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'call_id', 'pbx_server_id', 'name', 'duration'];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function pbxServer(): BelongsTo
    {
        return $this->belongsTo(PbxServer::class);
    }
}
