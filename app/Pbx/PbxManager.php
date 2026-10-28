<?php

namespace App\Pbx;

use App\Jobs\SyncPbxServer;
use App\Models\PbxServer;
use App\Pbx\Contracts\PbxDriver;
use App\Pbx\Data\PbxHealth;
use App\Support\Audit;
use InvalidArgumentException;

class PbxManager
{
    public function driver(PbxServer $server): PbxDriver
    {
        $class = config("pbx.drivers.{$server->driver}");

        if (! $class) {
            throw new InvalidArgumentException("Unknown PBX driver [{$server->driver}].");
        }

        return new $class($server);
    }

    /**
     * Probe a server and persist the result on its record.
     */
    public function checkHealth(PbxServer $server): PbxHealth
    {
        $health = $this->driver($server)->health();
        $previous = $server->status;
        $previousStartup = $server->last_health_payload['details']['startup_time'] ?? null;

        $server->forceFill([
            'status' => $health->online ? PbxServer::STATUS_ONLINE : PbxServer::STATUS_OFFLINE,
            'last_health_check_at' => now(),
            'last_health_payload' => $health->toArray(),
        ])->save();

        if ($previous !== $server->status) {
            Audit::record('pbx_server.status_changed', $server, ['from' => $previous, 'to' => $server->status]);
        }

        // Dynamic PJSIP objects and globals live in Asterisk's memory, so a restart (new
        // startup time) or a return from offline means extensions must be pushed again.
        $startup = $health->details['startup_time'] ?? null;
        $restarted = $previousStartup !== null && $startup !== null && $startup !== $previousStartup;

        if ($health->online && ($restarted || $previous === PbxServer::STATUS_OFFLINE)) {
            SyncPbxServer::dispatch($server->id);
        }

        return $health;
    }

    /**
     * @return list<string>
     */
    public static function availableDrivers(): array
    {
        $drivers = array_keys(config('pbx.drivers'));

        return app()->isProduction() ? array_values(array_diff($drivers, ['simulator'])) : $drivers;
    }
}
