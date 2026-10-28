<?php

namespace App\Pbx\Routing;

use App\Models\BusinessHour;
use App\Models\CallQueue;
use App\Models\Conference;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Holiday;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\OutboundRoute;
use App\Models\RingGroup;

/**
 * Turns one organization's routing configuration into the dialplan globals read by
 * docs/asterisk/extensions.conf. Pure: no PBX calls, so the output can be tested directly.
 *
 * Key layout (O = organization id):
 *   CPBX_O<O>_REC / _CID / _MAXSEC         recording mode, default caller id, call time limit
 *   CPBX_O<O>_HOURS, _HOURS_<n>            count + GotoIfTime specs for open hours
 *   CPBX_O<O>_CLOSED, _CLOSED_<n>          count + specs for holidays
 *   CPBX_O<O>_N_<number>                   type of a non-extension number (ringgroup|queue|conference|ivr)
 *   CPBX_O<O>_RG_<number>                  strategy|timeout|sip&sip|fallback
 *   CPBX_O<O>_Q_<number>                   queue name|max wait|fallback
 *   CPBX_O<O>_CF_<number>                  pin|max members|record
 *   CPBX_O<O>_IVR_<number>                 prompt|timeout|retries|timeout dest|invalid dest
 *   CPBX_O<O>_IVR_<number>_<digit>         destination (digit * = STAR, # = HASH)
 *   CPBX_O<O>_OUT, _OUT_<n>                count + regex|trunk endpoint|strip|prepend|caller id
 *   CPBX_DID_<digits>                      O|destination|after-hours destination
 */
class RoutingCompiler
{
    private const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * @return array<string, string>
     */
    public function compile(Organization $organization): array
    {
        $o = $organization->id;
        $p = "CPBX_O{$o}_";
        $tz = $organization->timezone ?: 'UTC';

        $extensions = Extension::withoutGlobalScopes()->where('organization_id', $o)->get(['id', 'number', 'sip_username', 'status']);
        $active = $extensions->where('status', Extension::STATUS_ACTIVE);
        $sipIds = $active->pluck('sip_username', 'id')->all();

        $ringGroups = RingGroup::withoutGlobalScopes()->where('organization_id', $o)->get();
        $queues = CallQueue::withoutGlobalScopes()->where('organization_id', $o)->get();
        $conferences = Conference::withoutGlobalScopes()->where('organization_id', $o)->get();
        // Draft IVR menus are not dialable until someone with ivr.publish puts them live.
        $ivrs = IvrMenu::withoutGlobalScopes()->where('organization_id', $o)->whereNotNull('published_at')->get();

        $numbers = [
            'extension' => $active->pluck('number', 'id')->all(),
            'ring_group' => $ringGroups->pluck('number', 'id')->all(),
            'queue' => $queues->pluck('number', 'id')->all(),
            'conference' => $conferences->pluck('number', 'id')->all(),
            'ivr' => $ivrs->pluck('number', 'id')->all(),
        ];
        $dest = fn (?string $d) => Destination::compile($d, $numbers, $sipIds);

        $call = $organization->setting('call');
        $values = [
            $p.'REC' => (string) ($call['recording_mode'] ?? 'none'),
            $p.'CID' => (string) ($call['default_caller_id'] ?? ''),
            $p.'MAXSEC' => (string) ((int) ($call['max_call_minutes'] ?? 120) * 60),
        ];

        $values += $this->list($p.'HOURS', $this->openHours($organization, $tz));
        $values += $this->list($p.'CLOSED', $this->holidays($organization, $tz));

        foreach ($ringGroups as $group) {
            $members = collect($group->members ?? [])->map(fn ($id) => $sipIds[(int) $id] ?? null)->filter()->implode('&');
            $values[$p.'N_'.$group->number] = 'ringgroup';
            $values[$p.'RG_'.$group->number] = implode('|', [$group->strategy, $group->ring_timeout, $members, $dest($group->fallback_destination)]);
        }

        foreach ($queues as $queue) {
            $values[$p.'N_'.$queue->number] = 'queue';
            $values[$p.'Q_'.$queue->number] = implode('|', [$queue->pbxName(), $queue->max_wait, $dest($queue->fallback_destination)]);
        }

        foreach ($conferences as $conference) {
            $values[$p.'N_'.$conference->number] = 'conference';
            $values[$p.'CF_'.$conference->number] = implode('|', [(string) $conference->pin, $conference->max_members, $conference->record ? '1' : '0']);
        }

        foreach ($ivrs as $ivr) {
            $key = $p.'IVR_'.$ivr->number;
            $values[$p.'N_'.$ivr->number] = 'ivr';
            $values[$key] = implode('|', [
                $ivr->greetingUrl() ?? '',
                $ivr->timeout,
                $ivr->max_retries,
                $dest($ivr->timeout_destination),
                $dest($ivr->invalid_destination),
            ]);

            foreach ($ivr->options ?? [] as $option) {
                $values[$key.'_'.self::digitKey((string) $option['digit'])] = $dest($option['destination'] ?? null);
            }
        }

        $routes = OutboundRoute::withoutGlobalScopes()
            ->where('organization_id', $o)
            ->where('active', true)
            ->whereHas('trunk', fn ($q) => $q->withoutGlobalScopes()->where('status', Extension::STATUS_ACTIVE))
            ->with(['trunk' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('priority')->orderBy('id')
            ->get();

        $values += $this->list($p.'OUT', $routes->map(fn (OutboundRoute $route) => implode('|', [
            OutboundRoute::patternToRegex($route->pattern),
            $route->trunk->endpointId(),
            $route->strip_digits,
            (string) $route->prepend,
            (string) $route->trunk->caller_id,
        ]))->all());

        DidNumber::withoutGlobalScopes()->where('organization_id', $o)->where('active', true)->get()
            ->each(function (DidNumber $did) use (&$values, $o, $dest) {
                $values['CPBX_DID_'.$did->number] = implode('|', [
                    $o,
                    $dest($did->destination),
                    $dest($did->after_hours_destination ?? $did->destination),
                ]);
            });

        return $values;
    }

    public static function digitKey(string $digit): string
    {
        return match ($digit) {
            '*' => 'STAR',
            '#' => 'HASH',
            default => $digit,
        };
    }

    /**
     * @param  list<string>  $items
     * @return array<string, string>
     */
    private function list(string $key, array $items): array
    {
        $values = [$key => (string) count($items)];

        foreach (array_values($items) as $i => $item) {
            $values[$key.'_'.($i + 1)] = $item;
        }

        return $values;
    }

    /**
     * GotoIfTime specs, one per open day: "09:00-17:00,mon,*,*,Asia/Colombo".
     *
     * @return list<string>
     */
    private function openHours(Organization $organization, string $tz): array
    {
        $saved = BusinessHour::withoutGlobalScopes()->where('organization_id', $organization->id)->get()->keyBy('day_of_week');

        $specs = [];
        foreach (range(0, 6) as $day) {
            // Same defaults as the settings page: weekdays 09:00–17:00 until hours are saved.
            $row = $saved[$day] ?? null;
            $open = $row ? $row->is_open : ($day >= 1 && $day <= 5);

            if ($open) {
                $from = substr($row->opens_at ?? '09:00', 0, 5);
                $to = substr($row->closes_at ?? '17:00', 0, 5);
                $specs[] = "{$from}-{$to},".self::WEEKDAYS[$day].",*,*,{$tz}";
            }
        }

        return $specs;
    }

    /**
     * Whole-day closures. GotoIfTime has no year, so one-off holidays are included only for the
     * coming year; the daily pbx:sync drops them once they have passed.
     *
     * @return list<string>
     */
    private function holidays(Organization $organization, string $tz): array
    {
        $today = now($tz)->startOfDay();

        return Holiday::withoutGlobalScopes()->where('organization_id', $organization->id)->get()
            ->filter(fn (Holiday $h) => $h->recurring || ($h->date->gte($today) && $h->date->lte($today->copy()->addYear())))
            ->map(fn (Holiday $h) => '*,*,'.$h->date->day.','.strtolower($h->date->format('M')).','.$tz)
            ->unique()
            ->values()
            ->all();
    }
}
