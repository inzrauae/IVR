<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class IvrMenu extends Model
{
    use BelongsToOrganization;

    /** "#" is not offered: Asterisk's Read() treats it as "end of input". */
    public const DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '*'];

    protected $fillable = [
        'organization_id', 'number', 'name', 'greeting_path', 'timeout', 'max_retries', 'options',
        'timeout_destination', 'invalid_destination', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'timeout' => 'integer',
            'max_retries' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * URL Asterisk fetches the greeting from (res_http_media_cache). The token is in the path,
     * not the query string, because dialplan file arguments treat "&" as a separator; it also
     * changes with each upload, so the PBX's media cache never serves a stale greeting.
     */
    public function greetingUrl(): ?string
    {
        return $this->greeting_path
            ? route('pbx-media.ivr', ['ivr' => $this->id, 'token' => $this->greetingToken()])
            : null;
    }

    public function greetingToken(): string
    {
        return substr(hash_hmac('sha256', "ivr:{$this->id}:{$this->greeting_path}", (string) config('app.key')), 0, 40);
    }
}
