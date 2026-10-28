<?php

namespace App\Pbx\Routing;

use App\Models\CallQueue;
use App\Models\Conference;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Extensions, ring groups, queues, conferences and IVR menus share one internal number space
 * per organization, so dialling a number is never ambiguous.
 */
final class NumberPlan
{
    /** @var array<string, class-string<Model>> */
    private const OWNERS = [
        'extension' => Extension::class,
        'ring group' => RingGroup::class,
        'queue' => CallQueue::class,
        'conference' => Conference::class,
        'IVR menu' => IvrMenu::class,
    ];

    /**
     * Who already uses this number in the current organization, ignoring $except.
     */
    public static function owner(string $number, ?Model $except = null): ?string
    {
        foreach (self::OWNERS as $label => $class) {
            $query = $class::where('number', $number);

            if ($except instanceof $class) {
                $query->whereKeyNot($except->getKey());
            }

            if ($query->exists()) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Validation rules for a number field; run inside the tenant context.
     *
     * @return list<mixed>
     */
    public static function rules(?Model $current = null): array
    {
        return [
            'required', 'string', 'regex:/^\d{2,10}$/',
            function (string $attribute, mixed $value, Closure $fail) use ($current) {
                if (is_string($value) && ($owner = self::owner($value, $current))) {
                    $fail("{$value} is already used by a {$owner}.");
                }
            },
        ];
    }

    /**
     * Next free number at or above $start.
     */
    public static function next(int $start): string
    {
        $taken = collect(self::OWNERS)->flatMap(fn ($class) => $class::pluck('number'))->map(fn ($n) => (int) $n)->flip();

        while ($taken->has($start)) {
            $start++;
        }

        return (string) $start;
    }
}
