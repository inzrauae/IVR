<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'description'];

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }
}
