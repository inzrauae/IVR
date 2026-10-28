<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Conference extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'number', 'name', 'pin', 'max_members', 'record'];

    protected function casts(): array
    {
        return ['record' => 'boolean', 'max_members' => 'integer'];
    }
}
