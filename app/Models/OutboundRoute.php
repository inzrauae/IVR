<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundRoute extends Model
{
    use BelongsToOrganization;

    /** Asterisk-style patterns: digits, X Z N, [ranges], optional leading +, optional trailing . or ! */
    public const PATTERN_REGEX = '/^\+?([0-9XZNxzn]|\[[0-9\-]+\])+[.!]?$/';

    protected $fillable = ['organization_id', 'sip_trunk_id', 'name', 'pattern', 'strip_digits', 'prepend', 'priority', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'strip_digits' => 'integer', 'priority' => 'integer'];
    }

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class, 'sip_trunk_id');
    }

    /**
     * Translate an Asterisk pattern (07XXXXXXXX, 00X., +94X.) into an anchored regular expression.
     */
    public static function patternToRegex(string $pattern): string
    {
        $map = ['X' => '[0-9]', 'Z' => '[1-9]', 'N' => '[2-9]', '.' => '[0-9]+', '!' => '[0-9]*', '+' => '[+]'];
        $regex = '';

        foreach (preg_split('/(\[[^\]]+\])/', strtoupper($pattern), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            $regex .= str_starts_with($part, '[') ? $part : strtr($part, $map);
        }

        return '^'.$regex.'$';
    }

    public function matches(string $number): bool
    {
        return (bool) preg_match('/'.self::patternToRegex($this->pattern).'/', $number);
    }
}
