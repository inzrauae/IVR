<?php

namespace App\Pbx\Routing;

use App\Models\CallQueue;
use App\Models\Conference;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use Closure;

/**
 * Where a call goes next: stored as "<type>:<value>" on DIDs, IVR options and fallbacks.
 * Internal targets reference record ids, so renumbering never breaks routing; a deleted
 * target compiles to a hang-up instead of a dangling number.
 */
final class Destination
{
    public const TYPES = [
        'extension' => 'Extensions',
        'ring_group' => 'Ring groups',
        'queue' => 'Call queues',
        'ivr' => 'IVR menus',
        'conference' => 'Conferences',
        'voicemail' => 'Voicemail',
        'external' => 'External number',
        'hangup' => 'Hang up',
    ];

    /** @var array<string, class-string> */
    private const MODELS = [
        'extension' => Extension::class,
        'voicemail' => Extension::class,
        'ring_group' => RingGroup::class,
        'queue' => CallQueue::class,
        'ivr' => IvrMenu::class,
        'conference' => Conference::class,
    ];

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parse(?string $destination): ?array
    {
        if ($destination === null || ! str_contains($destination, ':')) {
            return null;
        }

        [$type, $value] = explode(':', $destination, 2);

        return isset(self::TYPES[$type]) ? [$type, $value] : null;
    }

    /**
     * Validation rule; run inside the tenant context so only this organization's records pass.
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $parsed = is_string($value) ? self::parse($value) : null;

            $valid = match ($parsed[0] ?? null) {
                'hangup' => true,
                'external' => (bool) preg_match('/^\+?\d{3,20}$/', $parsed[1]),
                'voicemail' => Extension::whereKey((int) $parsed[1])->where('voicemail_enabled', true)->exists(),
                null => false,
                default => ctype_digit($parsed[1]) && (self::MODELS[$parsed[0]])::whereKey((int) $parsed[1])->exists(),
            };

            if (! $valid) {
                $fail('Choose a valid destination.');
            }
        };
    }

    /**
     * Every destination in the current organization, for pickers.
     *
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function options(): array
    {
        $options = [];
        $add = fn (string $type, $rows, Closure $label) => $rows->each(function ($row) use (&$options, $type, $label) {
            $options[] = ['value' => "{$type}:{$row->id}", 'label' => $label($row), 'group' => self::TYPES[$type]];
        });

        $extensions = Extension::orderBy('number')->get(['id', 'number', 'name', 'voicemail_enabled']);
        $add('extension', $extensions, fn ($e) => "{$e->number} · {$e->name}");
        $add('ring_group', RingGroup::orderBy('number')->get(['id', 'number', 'name']), fn ($r) => "{$r->number} · {$r->name}");
        $add('queue', CallQueue::orderBy('number')->get(['id', 'number', 'name']), fn ($r) => "{$r->number} · {$r->name}");
        $add('ivr', IvrMenu::orderBy('number')->get(['id', 'number', 'name']), fn ($r) => "{$r->number} · {$r->name}");
        $add('conference', Conference::orderBy('number')->get(['id', 'number', 'name']), fn ($r) => "{$r->number} · {$r->name}");
        $add('voicemail', $extensions->where('voicemail_enabled', true), fn ($e) => "Voicemail of {$e->number} · {$e->name}");
        $options[] = ['value' => 'hangup:', 'label' => 'Hang up', 'group' => self::TYPES['hangup']];

        return $options;
    }

    /**
     * The dialplan form read by docs/asterisk/extensions.conf ([cloudpbx-dest]):
     * num:<internal number>, vm:<sip id>, out:<digits> or hangup:.
     *
     * @param  array<string, array<int, string>>  $numbers  type => [id => internal number]
     * @param  array<int, string>  $sipIds  extension id => SIP id (active extensions only)
     */
    public static function compile(?string $destination, array $numbers, array $sipIds): string
    {
        [$type, $value] = self::parse($destination) ?? ['hangup', ''];

        return match ($type) {
            'external' => 'out:'.$value,
            'voicemail' => isset($sipIds[(int) $value]) ? 'vm:'.$sipIds[(int) $value] : 'hangup:',
            'hangup' => 'hangup:',
            default => isset($numbers[$type][(int) $value]) ? 'num:'.$numbers[$type][(int) $value] : 'hangup:',
        };
    }
}
