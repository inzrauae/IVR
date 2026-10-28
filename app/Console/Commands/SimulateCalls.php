<?php

namespace App\Console\Commands;

use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Pbx\Simulator\CallSimulator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fills call history, live calls, recordings and voicemail with realistic traffic for an
 * organization on a simulator PBX server, through the same event processor Asterisk feeds.
 */
class SimulateCalls extends Command
{
    protected $signature = 'pbx:simulate
        {organization : Organization id or slug}
        {--calls=20 : Finished calls to create}
        {--days=7 : Spread finished calls over this many past days}
        {--live=2 : Calls to leave ringing or connected}';

    protected $description = 'Generate simulated call traffic for an organization (development only)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('The call simulator cannot be used in production.');

            return self::FAILURE;
        }

        $arg = (string) $this->argument('organization');
        $organization = Organization::with('pbxServer')->where('id', ctype_digit($arg) ? (int) $arg : 0)->orWhere('slug', $arg)->first();

        if (! $organization) {
            $this->error("No organization [{$arg}].");

            return self::FAILURE;
        }

        $server = $organization->pbxServer;
        if (! $server || $server->driver !== 'simulator') {
            $this->error("{$organization->name} must be assigned to a PBX server using the simulator driver.");

            return self::FAILURE;
        }

        /** @var Collection<int, Extension> $extensions */
        $extensions = Extension::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'active')->get();
        if ($extensions->isEmpty()) {
            $this->error("{$organization->name} has no active extensions to call.");

            return self::FAILURE;
        }

        $dids = DidNumber::withoutGlobalScopes()->where('organization_id', $organization->id)->get();
        $recordAll = ($organization->setting('call.recording_mode') ?? 'none') !== 'none';
        $simulator = app(CallSimulator::class);
        $days = max(0, (int) $this->option('days'));

        $made = 0;
        for ($i = 0; $i < (int) $this->option('calls'); $i++) {
            // Business-hours timestamps spread over the period, oldest first.
            $at = now()->subDays($days ? random_int(0, $days) : 0)->setTime(random_int(8, 18), random_int(0, 59), random_int(0, 59));
            if ($at->isFuture()) {
                $at = now()->subMinutes(random_int(5, 120));
            }

            Carbon::setTestNow($at);
            $this->finishedCall($simulator, $server, $extensions, $dids, $recordAll);
            Carbon::setTestNow();
            $made++;
        }

        for ($i = 0; $i < (int) $this->option('live'); $i++) {
            $from = $extensions->random();
            $to = $extensions->count() > 1 ? $extensions->where('id', '!=', $from->id)->random() : $from;
            $linked = $simulator->dial($server, $from, $to->number);
            if ($i % 2 === 0) {
                $simulator->answer($server, $linked);
            }
        }

        $this->info("Created {$made} finished and ".(int) $this->option('live')." live call(s) for {$organization->name}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Extension>  $extensions
     * @param  Collection<int, DidNumber>  $dids
     */
    private function finishedCall(CallSimulator $simulator, $server, Collection $extensions, Collection $dids, bool $record): void
    {
        $agent = $extensions->random();
        $kind = $dids->isNotEmpty() ? random_int(1, 10) : random_int(5, 10);
        $outside = '07'.random_int(10000000, 79999999);

        $linked = match (true) {
            $kind <= 4 => $simulator->inbound($server, $dids->random(), $outside, $agent),
            $kind <= 7 => $simulator->dial($server, $agent, $outside),
            default => $simulator->dial($server, $agent, $extensions->count() > 1 ? $extensions->where('id', '!=', $agent->id)->random()->number : $agent->number),
        };

        $answered = random_int(1, 10) <= 7;

        if ($answered) {
            Carbon::setTestNow(now()->addSeconds(random_int(3, 20)));
            $simulator->answer($server, $linked);
            Carbon::setTestNow(now()->addSeconds(random_int(20, 600)));
            $simulator->hangup($server, $linked);

            if ($record) {
                $simulator->recorded($server, $linked);
            }

            return;
        }

        Carbon::setTestNow(now()->addSeconds(random_int(10, 30)));
        $simulator->hangup($server, $linked, '19');

        if ($kind <= 4 && $agent->voicemail_enabled && random_int(0, 1)) {
            $simulator->voicemail($server, $agent, $outside, random_int(5, 60));
        }
    }
}
