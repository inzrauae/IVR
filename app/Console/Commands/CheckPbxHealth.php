<?php

namespace App\Console\Commands;

use App\Models\PbxServer;
use App\Pbx\PbxManager;
use Illuminate\Console\Command;
use Throwable;

class CheckPbxHealth extends Command
{
    protected $signature = 'pbx:health';

    protected $description = 'Probe every active PBX server and record its status';

    public function handle(PbxManager $pbx): int
    {
        PbxServer::where('is_active', true)->each(function (PbxServer $server) use ($pbx) {
            try {
                $health = $pbx->checkHealth($server);
                $this->line(sprintf('%-30s %s', $server->name, $health->online ? 'online' : 'offline: '.$health->error));
            } catch (Throwable $e) {
                report($e);
                $this->error("{$server->name}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
