<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'date', 'recurring'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurring' => 'boolean',
        ];
    }
}
